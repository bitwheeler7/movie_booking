<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\ShowSeat;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const JWT_SECRET = 'booking-test-secret';

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.user_jwt_secret' => self::JWT_SECRET,
        ]);
    }

    public function test_owner_can_cancel_before_show_start_and_retries_do_not_duplicate_events(): void
    {
        [$booking, $seat, $ticket] = $this->createConfirmedBooking();
        Http::fake([
            '*shows/*' => Http::response([
                'id' => $booking->show_id,
                'start_time' => now()->addHour()->toIso8601String(),
            ]),
            '*payments/*/refund' => Http::response(['success' => true], 201),
        ]);
        $token = $this->tokenFor($booking->user_id);

        $this->withToken($token)
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.booking_status', 'CANCELLED')
            ->assertJsonPath('data.ticket.status', 'CANCELLED');

        $this->withToken($token)
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertOk();

        $this->assertSame('AVAILABLE', $seat->fresh()->status);
        $this->assertSame('CANCELLED', $ticket->fresh()->status);
        $this->assertDatabaseCount('outbox_events', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/payments/55/refund')
            && $request->hasHeader('Idempotency-Key', 'booking-cancellation-'.$booking->id)
        );
    }

    public function test_cancellation_rejects_another_users_booking(): void
    {
        [$booking] = $this->createConfirmedBooking();

        $this->withToken($this->tokenFor($booking->user_id + 1))
            ->postJson("/api/bookings/{$booking->id}/cancel")
            ->assertForbidden();
    }

    public function test_owner_can_get_an_issued_ticket(): void
    {
        [$booking, , $ticket] = $this->createConfirmedBooking();

        $this->withToken($this->tokenFor($booking->user_id))
            ->getJson("/api/bookings/{$booking->id}/ticket")
            ->assertOk()
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.ticket_number', $ticket->ticket_number);
    }

    public function test_missing_ticket_is_a_json_api_not_found_response(): void
    {
        $this->withToken($this->tokenFor(7))
            ->get('/api/bookings/999999/ticket', ['Accept' => '*/*'])
            ->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    private function createConfirmedBooking(): array
    {
        $lockToken = (string) Str::uuid();
        $booking = Booking::create([
            'booking_reference' => 'MB-'.bin2hex(random_bytes(5)),
            'user_id' => 7,
            'show_id' => 8,
            'total_amount' => 300,
            'booking_status' => 'CONFIRMED',
            'payment_status' => 'SUCCESS',
            'lock_token' => $lockToken,
            'lock_expires_at' => now()->subMinute(),
            'confirmed_at' => now()->subMinute(),
            'payment_id' => 55,
            'payment_reference' => 'PAY-55',
        ]);

        $seatId = random_int(10, 100000);
        $seat = ShowSeat::create([
            'show_id' => $booking->show_id,
            'seat_id' => $seatId,
            'seat_number' => 'A'.$seatId,
            'ticket_price' => 300,
            'status' => 'BOOKED',
            'booking_id' => $booking->id,
        ]);

        BookingSeat::create([
            'booking_id' => $booking->id,
            'show_seat_id' => $seat->id,
            'seat_id' => $seat->seat_id,
            'seat_number' => $seat->seat_number,
            'ticket_price' => $seat->ticket_price,
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'TKT-0000000001',
            'booking_id' => $booking->id,
            'user_id' => $booking->user_id,
            'show_id' => $booking->show_id,
            'seats' => [['seat_id' => $seatId, 'seat_number' => $seat->seat_number]],
            'status' => 'ISSUED',
            'issued_at' => now()->subMinute(),
        ]);

        return [$booking, $seat, $ticket];
    }

    private function tokenFor(int $userId): string
    {
        $encode = static fn (array $value): string => rtrim(
            strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'),
            '='
        );

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode([
            'sub' => (string) $userId,
            'iat' => time(),
            'exp' => time() + 3600,
        ]);
        $signature = rtrim(
            strtr(base64_encode(hash_hmac('sha256', $header.'.'.$payload, self::JWT_SECRET, true)), '+/', '-_'),
            '='
        );

        return $header.'.'.$payload.'.'.$signature;
    }
}
