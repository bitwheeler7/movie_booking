<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\ShowSeat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            $bookingReference = 'MB' . strtoupper(Str::random(12));

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
    public function show(string $id)
    {
        $booking = Booking::with('seats')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $booking,
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
}