<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\OutboxEvent;
use App\Models\ShowSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingConfirmationOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.payment.webhook_secret' => 'test-secret',
        ]);
    }

    public function test_signed_payment_confirms_booking_and_records_event_once(): void
    {
        [$booking, $seat] = $this->createPendingBooking();
        $payload = [
            'payment_id' => 33,
            'payment_reference' => 'PAY-123',
            'status' => 'SUCCESS',
        ];

        $this->sendConfirmation($booking->id, $payload)->assertOk();
        $this->sendConfirmation($booking->id, $payload)->assertOk();

        $booking->refresh();
        $seat->refresh();

        $this->assertSame('CONFIRMED', $booking->booking_status);
        $this->assertSame('SUCCESS', $booking->payment_status);
        $this->assertSame('BOOKED', $seat->status);
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('tickets', 1);
        $this->assertSame('ISSUED', $booking->ticket->status);
        $ticketNumber = $booking->ticket->ticket_number;

        $event = OutboxEvent::firstOrFail();
        $this->assertSame('booking.confirmed', $event->topic);
        $this->assertSame($booking->user_id, $event->payload['user_id']);
        $this->assertSame($booking->id, $event->payload['data']['booking_id']);
        $this->assertSame($booking->booking_reference, $event->payload['data']['booking_reference']);
        $this->assertSame($ticketNumber, $booking->fresh()->ticket->ticket_number);
    }

    public function test_invalid_payment_signature_does_not_confirm_booking(): void
    {
        [$booking] = $this->createPendingBooking();
        $payload = [
            'payment_id' => 33,
            'payment_reference' => 'PAY-123',
            'status' => 'SUCCESS',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call('POST', "/api/bookings/{$booking->id}/confirm-payment", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => 'invalid',
        ], $body)->assertUnauthorized();

        $this->assertSame('PENDING', $booking->fresh()->booking_status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_signed_confirmation_cannot_be_replayed_for_another_booking(): void
    {
        [$firstBooking] = $this->createPendingBooking();
        [$secondBooking] = $this->createPendingBooking();
        $payload = [
            'booking_id' => $firstBooking->id,
            'payment_id' => 33,
            'payment_reference' => 'PAY-123',
            'user_id' => $firstBooking->user_id,
            'amount' => $firstBooking->total_amount,
            'status' => 'SUCCESS',
        ];

        $this->sendConfirmation($firstBooking->id, $payload)->assertOk();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->call('POST', "/api/bookings/{$secondBooking->id}/confirm-payment", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret'),
        ], $body)->assertConflict();

        $this->assertSame('PENDING', $secondBooking->fresh()->booking_status);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_expired_lock_expires_booking_and_releases_its_seat(): void
    {
        [$booking, $seat] = $this->createPendingBooking();
        $booking->update(['lock_expires_at' => now()->subSecond()]);
        $seat->update(['lock_expires_at' => now()->subSecond()]);

        $this->sendConfirmation($booking->id, [
            'payment_id' => 33,
            'payment_reference' => 'PAY-123',
            'status' => 'SUCCESS',
        ])->assertConflict();

        $this->assertSame('EXPIRED', $booking->fresh()->booking_status);
        $this->assertSame('AVAILABLE', $seat->fresh()->status);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    private function createPendingBooking(): array
    {
        $lockToken = (string) Str::uuid();
        $booking = Booking::create([
            'booking_reference' => 'MB-'.bin2hex(random_bytes(5)),
            'user_id' => 7,
            'show_id' => 8,
            'total_amount' => 300,
            'booking_status' => 'PENDING',
            'payment_status' => 'PENDING',
            'lock_token' => $lockToken,
            'lock_expires_at' => now()->addMinutes(5),
        ]);

        $seat = ShowSeat::create([
            'show_id' => $booking->show_id,
            'seat_id' => $seatId = random_int(10, 100000),
            'seat_number' => 'A'.$seatId,
            'ticket_price' => 300,
            'status' => 'LOCKED',
            'locked_by' => $booking->user_id,
            'lock_token' => $lockToken,
            'lock_expires_at' => now()->addMinutes(5),
            'booking_id' => $booking->id,
        ]);

        BookingSeat::create([
            'booking_id' => $booking->id,
            'show_seat_id' => $seat->id,
            'seat_id' => $seat->seat_id,
            'seat_number' => $seat->seat_number,
            'ticket_price' => $seat->ticket_price,
        ]);

        return [$booking, $seat];
    }

    private function sendConfirmation(int $bookingId, array $payload)
    {
        $booking = Booking::findOrFail($bookingId);
        $payload = array_merge([
            'booking_id' => $booking->id,
            'payment_id' => 33,
            'payment_reference' => 'PAY-123',
            'user_id' => $booking->user_id,
            'amount' => $booking->total_amount,
            'status' => 'SUCCESS',
        ], $payload);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call('POST', "/api/bookings/{$bookingId}/confirm-payment", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_SIGNATURE' => hash_hmac('sha256', $body, 'test-secret'),
        ], $body);
    }
}
