<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class PaymentController extends Controller
{
    /**
     * Create a payment intent using the authoritative booking details.
     */
    public function createIntent(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|integer|min:1',
            'user_id' => 'required|integer|min:1',
            'payment_method' => 'nullable|string|max:30',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'A valid Idempotency-Key header is required.',
            ]);
        }

        // Fetch booking information from Booking Service.
        $response = Http::timeout(5)->get(
            rtrim(config('services.payment.booking_service_url'), '/')
                . '/bookings/' . $data['booking_id']
        );

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve booking information.',
            ], 502);
        }

        $booking = $response->json('data');

        if (!$booking) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid booking response.',
            ], 502);
        }

        if ((int) $booking['user_id'] !== (int) $data['user_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Booking does not belong to this user.',
            ], 403);
        }

        if ($booking['booking_status'] !== 'PENDING') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending bookings can be paid.',
            ], 422);
        }

        if (Carbon::parse($booking['lock_expires_at'])->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'The seat lock has expired.',
            ], 422);
        }

        $payment = DB::transaction(function () use (
            $data,
            $idempotencyKey,
            $booking
        ) {
            $existing = Payment::where(
                'idempotency_key',
                $idempotencyKey
            )->lockForUpdate()->first();

            if ($existing) {
                if ((int) $existing->booking_id !== (int) $booking['id']) {
                    abort(409, 'Idempotency key was already used for another booking.');
                }

                return $existing;
            }

            return Payment::create([
                'booking_id' => $booking['id'],
                'user_id' => $data['user_id'],
                'payment_reference' => 'PAY-' . strtoupper(Str::random(14)),
                'idempotency_key' => $idempotencyKey,
                'amount' => $booking['total_amount'],
                'currency' => 'INR',
                'payment_method' => $data['payment_method'] ?? null,
                'gateway_name' => config('services.payment.gateway_name'),
                'status' => 'PENDING',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment intent created.',
            'data' => $payment,
        ], 201);
    }

    /**
     * Verify a signed gateway callback.
     */
    public function webhook(Request $request)
    {
        $secret = config('services.payment.webhook_secret');

        $signature = $request->header('X-Payment-Signature');

        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            $secret
        );

        if (
            !$signature
            || !hash_equals($expectedSignature, $signature)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment signature.',
            ], 401);
        }

        $data = $request->validate([
            'payment_reference' => 'required|string|max:50',
            'gateway_transaction_id' => 'required|string|max:100',
            'status' => 'required|in:SUCCESS,FAILED',
            'paid_at' => 'nullable|date',
        ]);

        $payment = DB::transaction(function () use ($data, $request) {

            $payment = Payment::where(
                'payment_reference',
                $data['payment_reference']
            )->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'PENDING') {
                // Repeated identical callbacks are safe to acknowledge.
                if (
                    $payment->status === $data['status']
                    && $payment->gateway_transaction_id
                        === $data['gateway_transaction_id']
                ) {
                    return $payment;
                }

                abort(409, 'Payment has already reached a different final status.');
            }

            if ($data['status'] === 'SUCCESS') {
                if (empty($data['paid_at'])) {
                    abort(422, 'A verified payment timestamp is required.');
                }

                $paidAt = Carbon::parse($data['paid_at']);

                if ($paidAt->isFuture()) {
                    abort(422, 'Payment timestamp cannot be in the future.');
                }

                $payment->update([
                    'status' => 'SUCCESS',
                    'gateway_transaction_id' => $data['gateway_transaction_id'],
                    'paid_at' => $paidAt,
                    'gateway_response' => $request->all(),
                ]);
            } else {
                $payment->update([
                    'status' => 'FAILED',
                    'gateway_transaction_id' => $data['gateway_transaction_id'],
                    'gateway_response' => $request->all(),
                ]);
            }

            return $payment->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Payment callback processed.',
            'data' => $payment,
        ]);
    }

    /**
     * Get payment details.
     */
    public function show(string $id)
    {
        $payment = Payment::with('refunds')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }

    /**
     * Request a refund for a successful payment.
     * This creates a refund request; it does not claim the gateway
     * has actually transferred the money back.
     */
    public function requestRefund(Request $request, string $id)
    {
        $data = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $refund = DB::transaction(function () use ($id, $data) {

            $payment = Payment::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($payment->status, ['SUCCESS', 'REFUND_PENDING'], true)) {
                abort(422, 'Only successful payments can be refunded.');
            }

            $alreadyRefunded = (float) Refund::where(
                'payment_id',
                $payment->id
            )->whereIn('status', ['PENDING', 'SUCCESS'])
                ->sum('amount');

            $remaining = (float) $payment->amount - $alreadyRefunded;

            $amount = $data['amount'] ?? $remaining;

            if ($amount > $remaining) {
                abort(422, 'Refund amount exceeds the remaining refundable amount.');
            }

            if ($amount <= 0) {
                abort(422, 'No refundable balance remains.');
            }

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'refund_reference' => 'REF-' . strtoupper(Str::random(14)),
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'status' => 'PENDING',
            ]);

            $payment->update([
                'status' => 'REFUND_PENDING',
            ]);

            return $refund;
        });

        return response()->json([
            'success' => true,
            'message' => 'Refund request created. Gateway processing is pending.',
            'data' => $refund,
        ], 201);
    }
    public function refundWebhook(Request $request)
{
    $signature = $request->header('X-Payment-Signature');

    $expectedSignature = hash_hmac(
        'sha256',
        $request->getContent(),
        config('services.payment.webhook_secret')
    );

    if (
        !$signature
        || !hash_equals($expectedSignature, $signature)
    ) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid refund signature.',
        ], 401);
    }

    $data = $request->validate([
        'refund_reference' => 'required|string|max:50',
        'gateway_refund_id' => 'required|string|max:100',
        'status' => 'required|in:SUCCESS,FAILED',
        'refunded_at' => 'nullable|date',
    ]);

    $refund = DB::transaction(function () use ($data, $request) {

        $refund = Refund::where(
            'refund_reference',
            $data['refund_reference']
        )->lockForUpdate()->firstOrFail();

        $payment = Payment::whereKey($refund->payment_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($refund->status !== 'PENDING') {
            if (
                $refund->status === $data['status']
                && $refund->gateway_refund_id === $data['gateway_refund_id']
            ) {
                return $refund;
            }

            abort(409, 'Refund has already reached a different final status.');
        }

        $refund->update([
            'status' => $data['status'],
            'gateway_refund_id' => $data['gateway_refund_id'],
            'refunded_at' => $data['status'] === 'SUCCESS'
                ? ($data['refunded_at'] ?? now())
                : null,
            'gateway_response' => $request->all(),
        ]);

        $pendingRefunds = Refund::where('payment_id', $payment->id)
            ->where('status', 'PENDING')
            ->exists();

        $successfulRefunds = (float) Refund::where(
            'payment_id',
            $payment->id
        )->where('status', 'SUCCESS')->sum('amount');

        if ($pendingRefunds) {
            $payment->update(['status' => 'REFUND_PENDING']);
        } elseif ($successfulRefunds >= (float) $payment->amount) {
            $payment->update(['status' => 'REFUNDED']);
        } else {
            $payment->update(['status' => 'SUCCESS']);
        }

        return $refund->fresh();
    });

    return response()->json([
        'success' => true,
        'message' => 'Refund callback processed.',
        'data' => $refund,
    ]);
}
}