<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\EventOutboxService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

        if ((int) $data['user_id'] !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot create a payment for another user.');
        }

        $idempotencyKey = $request->header('Idempotency-Key');

        if (! $idempotencyKey || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'A valid Idempotency-Key header is required.',
            ]);
        }

        // Fetch booking information from Booking Service.
        $response = Http::timeout(5)
            ->withToken($request->bearerToken())
            ->get(
                rtrim(config('services.payment.booking_service_url'), '/')
                    .'/bookings/'.$data['booking_id']
            );

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve booking information.',
            ], 502);
        }

        $booking = $response->json('data');

        if (! $booking) {
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
                'payment_reference' => 'PAY-'.strtoupper(Str::random(14)),
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

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Payment webhook verification is not configured.',
            ], 503);
        }

        $signature = $request->header('X-Payment-Signature');

        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            $secret
        );

        if (
            ! $signature
            || ! hash_equals($expectedSignature, $signature)
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
                    (
                        $payment->status === $data['status']
                        || (
                            $data['status'] === 'SUCCESS'
                            && in_array($payment->status, ['REFUND_PENDING', 'REFUNDED'], true)
                        )
                    )
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

                app(EventOutboxService::class)->record(
                    'payment.succeeded',
                    'payment.succeeded',
                    $payment->user_id,
                    [
                        'payment_id' => $payment->id,
                        'booking_id' => $payment->booking_id,
                        'payment_reference' => $payment->payment_reference,
                        'amount' => $payment->amount,
                        'currency' => $payment->currency,
                        'gateway_transaction_id' => $payment->gateway_transaction_id,
                    ]
                );
            } else {
                $payment->update([
                    'status' => 'FAILED',
                    'gateway_transaction_id' => $data['gateway_transaction_id'],
                    'gateway_response' => $request->all(),
                ]);

                app(EventOutboxService::class)->record(
                    'payment.failed',
                    'payment.failed',
                    $payment->user_id,
                    [
                        'payment_id' => $payment->id,
                        'booking_id' => $payment->booking_id,
                        'payment_reference' => $payment->payment_reference,
                    ]
                );
            }

            return $payment->fresh();
        });

        if ($payment->status === 'SUCCESS') {
            $bookingConfirmation = [
                'booking_id' => $payment->booking_id,
                'payment_id' => $payment->id,
                'payment_reference' => $payment->payment_reference,
                'user_id' => $payment->user_id,
                'amount' => $payment->amount,
                'status' => 'SUCCESS',
            ];
            $confirmationBody = json_encode($bookingConfirmation, JSON_THROW_ON_ERROR);

            try {
                $confirmationResponse = Http::timeout(5)
                    ->withHeaders([
                        'X-Payment-Signature' => hash_hmac(
                            'sha256',
                            $confirmationBody,
                            $secret
                        ),
                    ])
                    ->withBody($confirmationBody, 'application/json')
                    ->post(
                        rtrim(config('services.payment.booking_service_url'), '/')
                            .'/bookings/'.$payment->booking_id.'/confirm-payment'
                    );
            } catch (ConnectionException $e) {
                Log::error('Booking confirmation request failed', [
                    'booking_id' => $payment->booking_id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment succeeded, but booking confirmation is temporarily unavailable.',
                ], 502);
            }

            if (! $confirmationResponse->successful()) {
                if ($confirmationResponse->status() === 409) {
                    $refund = $this->requestAutomaticRefund($payment);

                    return response()->json([
                        'success' => true,
                        'message' => 'Payment succeeded after the booking could not be confirmed; refund processing is pending.',
                        'data' => [
                            'payment' => $payment,
                            'refund' => $refund,
                        ],
                    ], 202);
                }

                Log::error('Booking confirmation was rejected', [
                    'booking_id' => $payment->booking_id,
                    'payment_id' => $payment->id,
                    'http_status' => $confirmationResponse->status(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Payment succeeded, but booking confirmation failed.',
                ], 502);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment callback processed.',
            'data' => $payment,
        ]);
    }

    /**
     * Get payment details.
     */
    public function show(string $id, Request $request)
    {
        $payment = Payment::with('refunds')->findOrFail($id);

        if ((int) $payment->user_id !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot view another user’s payment.');
        }

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

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null && (! $idempotencyKey || strlen($idempotencyKey) > 100)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key header must be 1 to 100 characters.',
            ]);
        }

        $authenticatedUserId = (int) $request->attributes->get('authenticated_user_id');
        $existing = false;

        $refund = DB::transaction(function () use ($id, $data, $idempotencyKey, $authenticatedUserId, &$existing) {

            $payment = Payment::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $payment->user_id !== $authenticatedUserId) {
                abort(403, 'You cannot request a refund for another user’s payment.');
            }

            if ($idempotencyKey) {
                $existingRefund = Refund::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingRefund) {
                    if ((int) $existingRefund->payment_id !== (int) $payment->id) {
                        abort(409, 'Idempotency key was already used for another payment.');
                    }

                    $existing = true;

                    if ($existingRefund->status === 'FAILED') {
                        $existingRefund->update([
                            'status' => 'PENDING',
                            'gateway_refund_id' => null,
                            'refunded_at' => null,
                            'gateway_response' => null,
                        ]);
                        $payment->update(['status' => 'REFUND_PENDING']);

                        return $existingRefund->fresh();
                    }

                    return $existingRefund;
                }
            }

            if (! in_array($payment->status, ['SUCCESS', 'REFUND_PENDING'], true)) {
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
                'refund_reference' => 'REF-'.strtoupper(Str::random(14)),
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
                'status' => 'PENDING',
                'idempotency_key' => $idempotencyKey,
            ]);

            $payment->update([
                'status' => 'REFUND_PENDING',
            ]);

            return $refund;
        });

        return response()->json([
            'success' => true,
            'message' => $existing
                ? 'Existing refund request returned.'
                : 'Refund request created. Gateway processing is pending.',
            'data' => $refund,
        ], $existing ? 200 : 201);
    }

    public function refundWebhook(Request $request)
    {
        $secret = config('services.payment.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Refund webhook verification is not configured.',
            ], 503);
        }

        $signature = $request->header('X-Payment-Signature');

        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            $secret
        );

        if (
            ! $signature
            || ! hash_equals($expectedSignature, $signature)
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

            if ($data['status'] === 'SUCCESS') {
                app(EventOutboxService::class)->record(
                    'payment.refunded',
                    'payment.refunded',
                    $payment->user_id,
                    [
                        'payment_id' => $payment->id,
                        'booking_id' => $payment->booking_id,
                        'refund_id' => $refund->id,
                        'refund_reference' => $refund->refund_reference,
                        'amount' => $refund->amount,
                    ]
                );
            }

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

    private function requestAutomaticRefund(Payment $payment): Refund
    {
        return DB::transaction(function () use ($payment) {
            $lockedPayment = Payment::whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $refundedOrPending = (float) Refund::where('payment_id', $lockedPayment->id)
                ->whereIn('status', ['PENDING', 'SUCCESS'])
                ->sum('amount');
            $remaining = max(0, (float) $lockedPayment->amount - $refundedOrPending);

            if ($remaining > 0) {
                $refundReference = 'REF-AUTO-'.$lockedPayment->id;
                $refund = Refund::firstOrCreate(
                    ['refund_reference' => $refundReference],
                    [
                        'payment_id' => $lockedPayment->id,
                        'amount' => $remaining,
                        'reason' => 'Booking could not be confirmed after payment.',
                        'status' => 'PENDING',
                    ]
                );
            } else {
                $refund = Refund::where('payment_id', $lockedPayment->id)
                    ->whereIn('status', ['PENDING', 'SUCCESS'])
                    ->latest('id')
                    ->firstOrFail();
            }

            $successfulRefunds = (float) Refund::where('payment_id', $lockedPayment->id)
                ->where('status', 'SUCCESS')
                ->sum('amount');
            $pendingRefunds = Refund::where('payment_id', $lockedPayment->id)
                ->where('status', 'PENDING')
                ->exists();

            $lockedPayment->update([
                'status' => $pendingRefunds
                    ? 'REFUND_PENDING'
                    : ($successfulRefunds >= (float) $lockedPayment->amount ? 'REFUNDED' : 'SUCCESS'),
            ]);

            return $refund;
        });
    }
}
