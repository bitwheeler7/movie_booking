<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\ShowSeat;
use App\Models\Ticket;
use App\Services\EventOutboxService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    /**
     * Lock 1 to 10 seats for 8 minutes.
     */
    public function lockSeats(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|min:1',
            'show_id' => 'required|integer|min:1',
            'seat_ids' => 'required|array|min:1|max:10',
            'seat_ids.*' => 'required|integer|min:1|distinct',
        ]);

        $seatIds = collect($data['seat_ids'])
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        if ((int) $data['user_id'] !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot create a booking for another user.');
        }

        $result = DB::transaction(function () use ($data, $seatIds) {

            // Lock all requested show-seat rows in a consistent order.
            $seats = ShowSeat::where('show_id', $data['show_id'])
                ->whereIn('seat_id', $seatIds)
                ->orderBy('seat_id')
                ->lockForUpdate()
                ->get();

            if ($seats->count() !== count($seatIds)) {
                throw ValidationException::withMessages([
                    'seat_ids' => 'Some selected seats do not exist for this show.',
                ]);
            }

            $now = now();

            // Reject seats already booked or locked by another active booking.
            foreach ($seats as $seat) {
                if ($seat->status === 'BOOKED') {
                    throw ValidationException::withMessages([
                        'seat_ids' => "Seat {$seat->seat_number} is already booked.",
                    ]);
                }

                if (
                    $seat->status === 'LOCKED'
                    && $seat->lock_expires_at
                    && $seat->lock_expires_at->gt($now)
                ) {
                    throw ValidationException::withMessages([
                        'seat_ids' => "Seat {$seat->seat_number} is temporarily locked.",
                    ]);
                }
            }

            $lockToken = (string) Str::uuid();
            $expiresAt = $now->copy()->addMinutes(8);
            $bookingReference = 'MB'.strtoupper(Str::random(12));

            $totalAmount = $seats->sum(
                fn ($seat) => (float) $seat->ticket_price
            );

            $booking = Booking::create([
                'booking_reference' => $bookingReference,
                'user_id' => $data['user_id'],
                'show_id' => $data['show_id'],
                'total_amount' => $totalAmount,
                'booking_status' => 'PENDING',
                'payment_status' => 'PENDING',
                'lock_token' => $lockToken,
                'lock_expires_at' => $expiresAt,
            ]);

            foreach ($seats as $seat) {
                $seat->update([
                    'status' => 'LOCKED',
                    'locked_by' => $data['user_id'],
                    'lock_token' => $lockToken,
                    'lock_expires_at' => $expiresAt,
                    'booking_id' => $booking->id,
                ]);

                BookingSeat::create([
                    'booking_id' => $booking->id,
                    'show_seat_id' => $seat->id,
                    'seat_id' => $seat->seat_id,
                    'seat_number' => $seat->seat_number,
                    'ticket_price' => $seat->ticket_price,
                ]);
            }

            return $booking->load('seats');
        }, 3);

        return response()->json([
            'success' => true,
            'message' => 'Seats locked successfully for 8 minutes.',
            'data' => $result,
        ], 201);
    }

    /**
     * Check availability for a show.
     */
    public function availability(string $showId)
    {
        $seats = ShowSeat::where('show_id', $showId)
            ->orderBy('seat_number')
            ->get()
            ->map(function ($seat) {
                $available = $seat->status === 'AVAILABLE'
                    || (
                        $seat->status === 'LOCKED'
                        && $seat->lock_expires_at
                        && $seat->lock_expires_at->lte(now())
                    );

                return [
                    'seat_id' => $seat->seat_id,
                    'seat_number' => $seat->seat_number,
                    'ticket_price' => $seat->ticket_price,
                    'status' => $available ? 'AVAILABLE' : $seat->status,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $seats,
        ]);
    }

    /**
     * Get a booking.
     */
    public function show(string $id, Request $request)
    {
        $booking = Booking::with(['seats', 'ticket'])->findOrFail($id);

        if ((int) $booking->user_id !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot view another user’s booking.');
        }

        return response()->json([
            'success' => true,
            'data' => $booking,
        ]);
    }

    public function ticket(string $id, Request $request)
    {
        $booking = Booking::with('ticket')->findOrFail($id);

        if ((int) $booking->user_id !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot view another user’s ticket.');
        }

        if ($booking->booking_status !== 'CONFIRMED' || ! $booking->ticket) {
            abort(404, 'Ticket is not available.');
        }

        return response()->json([
            'success' => true,
            'data' => $booking->ticket,
        ]);
    }

    /**
     * Release a pending booking's locks.
     * Intended for a user abandoning checkout.
     */
    public function release(string $id, Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer|min:1',
        ]);

        if ((int) $data['user_id'] !== (int) $request->attributes->get('authenticated_user_id')) {
            abort(403, 'You cannot release another user’s booking.');
        }

        DB::transaction(function () use ($id, $data) {

            $booking = Booking::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $booking->user_id !== (int) $data['user_id']) {
                abort(403, 'You cannot release another user’s booking.');
            }

            if ($booking->booking_status !== 'PENDING') {
                abort(422, 'Only pending bookings can be released.');
            }

            $seats = ShowSeat::where('booking_id', $booking->id)
                ->where('lock_token', $booking->lock_token)
                ->lockForUpdate()
                ->get();

            foreach ($seats as $seat) {
                $seat->update([
                    'status' => 'AVAILABLE',
                    'locked_by' => null,
                    'lock_token' => null,
                    'lock_expires_at' => null,
                    'booking_id' => null,
                ]);
            }

            $booking->update([
                'booking_status' => 'EXPIRED',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Booking lock released.',
        ]);
    }

    public function cancel(string $id, Request $request)
    {
        $userId = (int) $request->attributes->get('authenticated_user_id');
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $booking = DB::transaction(function () use ($id, $userId) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();

            if ((int) $booking->user_id !== $userId) {
                abort(403, 'You cannot cancel another user’s booking.');
            }

            if ($booking->booking_status === 'CANCELLED') {
                return $booking->load('ticket');
            }

            if (! in_array($booking->booking_status, ['CONFIRMED', 'CANCELLATION_PENDING'], true)) {
                abort(422, 'Only confirmed bookings can be cancelled.');
            }

            if (! $booking->payment_id) {
                abort(409, 'A payment record is required to cancel this booking.');
            }

            return $booking;
        });

        if ($booking->booking_status === 'CANCELLED') {
            return response()->json([
                'success' => true,
                'message' => 'Booking is already cancelled.',
                'data' => $booking,
            ]);
        }

        $token = $request->bearerToken();

        try {
            $showResponse = Http::timeout(5)
                ->withToken($token)
                ->get(
                    rtrim(config('services.booking.catalog_service_url'), '/')
                        .'/shows/'.$booking->show_id
                );
        } catch (ConnectionException $e) {
            Log::error('Unable to verify show start time for cancellation', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Show information is temporarily unavailable.',
            ], 502);
        }

        $show = $showResponse->json();
        if (! $showResponse->successful() || ! is_array($show) || empty($show['start_time'])) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify the show cancellation window.',
            ], 502);
        }

        try {
            if (Carbon::parse($show['start_time'])->lte(now())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bookings cannot be cancelled after the show has started.',
                ], 422);
            }
        } catch (\Throwable $e) {
            Log::error('Invalid show start time received for cancellation', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to verify the show cancellation window.',
            ], 502);
        }

        $booking = DB::transaction(function () use ($booking) {
            $lockedBooking = Booking::whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->booking_status === 'CANCELLED') {
                return $lockedBooking->load('ticket');
            }

            if ($lockedBooking->booking_status === 'CONFIRMED') {
                $lockedBooking->update(['booking_status' => 'CANCELLATION_PENDING']);
            } elseif ($lockedBooking->booking_status !== 'CANCELLATION_PENDING') {
                abort(409, 'Booking changed while cancellation was being processed.');
            }

            return $lockedBooking->fresh();
        });

        if ($booking->booking_status === 'CANCELLED') {
            return response()->json([
                'success' => true,
                'message' => 'Booking is already cancelled.',
                'data' => $booking,
            ]);
        }

        try {
            $refundResponse = Http::timeout(5)
                ->withToken($token)
                ->withHeaders([
                    'Idempotency-Key' => 'booking-cancellation-'.$booking->id,
                ])
                ->post(
                    rtrim(config('services.booking.payment_service_url'), '/')
                        .'/payments/'.$booking->payment_id.'/refund',
                    ['reason' => $data['reason'] ?? 'Booking cancellation']
                );
        } catch (ConnectionException $e) {
            Log::error('Unable to request cancellation refund', [
                'booking_id' => $booking->id,
                'payment_id' => $booking->payment_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Refund service is temporarily unavailable; retry cancellation.',
            ], 502);
        }

        if (! $refundResponse->successful()) {
            Log::error('Cancellation refund request failed', [
                'booking_id' => $booking->id,
                'payment_id' => $booking->payment_id,
                'http_status' => $refundResponse->status(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Refund could not be requested; retry cancellation.',
            ], 502);
        }

        $cancelledBooking = DB::transaction(function () use ($booking) {
            $lockedBooking = Booking::whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->booking_status === 'CANCELLED') {
                return $lockedBooking->load('ticket');
            }

            if ($lockedBooking->booking_status !== 'CANCELLATION_PENDING') {
                abort(409, 'Booking changed while cancellation was being processed.');
            }

            $seats = ShowSeat::where('booking_id', $lockedBooking->id)
                ->where('status', 'BOOKED')
                ->lockForUpdate()
                ->get();

            foreach ($seats as $seat) {
                $seat->update([
                    'status' => 'AVAILABLE',
                    'locked_by' => null,
                    'lock_token' => null,
                    'lock_expires_at' => null,
                    'booking_id' => null,
                ]);
            }

            $lockedBooking->update([
                'booking_status' => 'CANCELLED',
                'payment_status' => 'REFUND_PENDING',
                'cancelled_at' => now(),
            ]);

            $ticket = $lockedBooking->ticket()->lockForUpdate()->firstOrFail();
            $ticket->update(['status' => 'CANCELLED']);

            app(EventOutboxService::class)->record(
                'booking.cancelled',
                'booking.cancelled',
                $lockedBooking->user_id,
                [
                    'booking_id' => $lockedBooking->id,
                    'booking_reference' => $lockedBooking->booking_reference,
                    'show_id' => $lockedBooking->show_id,
                ]
            );

            return $lockedBooking->fresh()->load('ticket');
        });

        return response()->json([
            'success' => true,
            'message' => 'Booking cancelled and refund requested.',
            'data' => $cancelledBooking,
        ]);
    }

    /**
     * Confirm a booking after a signed payment-service callback.
     */
    public function confirmPayment(string $id, Request $request)
    {
        $secret = config('services.payment.webhook_secret');
        $signature = $request->header('X-Payment-Signature');

        if (! is_string($secret) || $secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Payment callback verification is not configured.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            $secret
        );

        if (! $signature || ! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid payment signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $data = $request->validate([
            'booking_id' => 'required|integer|min:1',
            'payment_id' => 'required|integer|min:1',
            'payment_reference' => 'required|string|max:50',
            'user_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|in:SUCCESS',
        ]);

        if ((int) $data['booking_id'] !== (int) $id) {
            abort(409, 'Payment confirmation is for a different booking.');
        }

        $result = DB::transaction(function () use ($id, $data) {
            $booking = Booking::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $booking->booking_status === 'CONFIRMED'
                && $booking->payment_status === 'SUCCESS'
            ) {
                if (
                    (int) $booking->payment_id !== (int) $data['payment_id']
                    || $booking->payment_reference !== $data['payment_reference']
                ) {
                    abort(409, 'Booking was confirmed by a different payment.');
                }

                return ['confirmed' => true, 'booking' => $booking->load('ticket')];
            }

            if ($booking->booking_status !== 'PENDING') {
                abort(409, 'Only pending bookings can be confirmed.');
            }

            if (
                (int) $booking->user_id !== (int) $data['user_id']
                || number_format((float) $booking->total_amount, 2, '.', '')
                    !== number_format((float) $data['amount'], 2, '.', '')
            ) {
                abort(409, 'Payment details do not match the booking.');
            }

            $seats = ShowSeat::where('booking_id', $booking->id)
                ->where('lock_token', $booking->lock_token)
                ->lockForUpdate()
                ->get();

            if (
                ! $booking->lock_expires_at
                || $booking->lock_expires_at->lte(now())
                || $seats->contains(fn ($seat) => ! $seat->lock_expires_at || $seat->lock_expires_at->lte(now()))
            ) {
                foreach ($seats as $seat) {
                    if ($seat->status === 'LOCKED') {
                        $seat->update([
                            'status' => 'AVAILABLE',
                            'locked_by' => null,
                            'lock_token' => null,
                            'lock_expires_at' => null,
                            'booking_id' => null,
                        ]);
                    }
                }

                $booking->update([
                    'booking_status' => 'EXPIRED',
                    'payment_status' => 'SUCCESS',
                ]);

                return ['confirmed' => false, 'booking' => $booking->fresh()];
            }

            if (
                $seats->count() !== $booking->seats()->count()
                || $seats->contains(fn ($seat) => $seat->status !== 'LOCKED')
            ) {
                abort(409, 'The booking seat lock is no longer valid.');
            }

            foreach ($seats as $seat) {
                $seat->update([
                    'status' => 'BOOKED',
                    'locked_by' => null,
                    'lock_token' => null,
                    'lock_expires_at' => null,
                ]);
            }

            $issuedAt = now();
            $ticket = Ticket::create([
                'booking_id' => $booking->id,
                'user_id' => $booking->user_id,
                'show_id' => $booking->show_id,
                'seats' => $booking->seats()->get(['seat_id', 'seat_number'])->toArray(),
                'status' => 'ISSUED',
                'issued_at' => $issuedAt,
            ]);
            $ticket->update([
                'ticket_number' => 'TKT-'.str_pad((string) $ticket->id, 10, '0', STR_PAD_LEFT),
            ]);

            $booking->update([
                'booking_status' => 'CONFIRMED',
                'payment_status' => 'SUCCESS',
                'confirmed_at' => now(),
                'payment_id' => $data['payment_id'],
                'payment_reference' => $data['payment_reference'],
            ]);

            app(EventOutboxService::class)->record(
                'booking.confirmed',
                'booking.confirmed',
                $booking->user_id,
                [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'show_id' => $booking->show_id,
                    'total_amount' => $booking->total_amount,
                ]
            );

            return ['confirmed' => true, 'booking' => $booking->fresh()->load('ticket')];
        });

        if (! $result['confirmed']) {
            return response()->json([
                'success' => false,
                'message' => 'The booking seat lock expired before payment confirmation.',
                'data' => $result['booking'],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'success' => true,
            'message' => 'Booking confirmed.',
            'data' => $result['booking'],
        ]);
    }
}
