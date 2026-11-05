<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private const JWT_SECRET = 'notification-test-secret';

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.user_jwt_secret' => self::JWT_SECRET,
        ]);
    }

    public function test_authenticated_user_can_list_and_read_only_their_own_notifications(): void
    {
        $own = $this->createNotification(10, 'event-own');
        $other = $this->createNotification(20, 'event-other');
        $token = $this->tokenFor(10);

        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->withToken($token)
            ->getJson("/api/notifications/{$other->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->getJson("/api/notifications/{$own->id}")
            ->assertOk()
            ->assertJsonPath('data.event_id', 'event-own');
    }

    public function test_read_state_and_delivery_status_are_exposed(): void
    {
        $unread = $this->createNotification(10, 'event-unread');
        $read = $this->createNotification(10, 'event-read', now());
        $token = $this->tokenFor(10);

        $this->withToken($token)
            ->getJson('/api/notifications/unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $unread->id);

        $this->withToken($token)
            ->postJson("/api/notifications/{$unread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $unread->id);

        $this->withToken($token)
            ->getJson("/api/notifications/{$unread->id}/delivery-status")
            ->assertOk()
            ->assertJsonPath('data.delivery_status', 'DELIVERED');

        $pending = $this->createNotification(10, 'event-pending');
        $this->withToken($token)
            ->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertNotNull($read->fresh()->read_at);
        $this->assertNotNull($unread->fresh()->read_at);
        $this->assertNotNull($pending->fresh()->read_at);
    }

    public function test_api_rejects_missing_or_invalid_bearer_tokens(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();

        $this->withToken('invalid')
            ->getJson('/api/notifications')
            ->assertUnauthorized();
    }

    private function createNotification(int $userId, string $eventId, $readAt = null): UserNotification
    {
        return UserNotification::create([
            'event_id' => $eventId,
            'event_type' => 'booking.confirmed',
            'topic' => 'booking.confirmed',
            'user_id' => $userId,
            'title' => 'Booking confirmed',
            'message' => 'Booking is confirmed',
            'data' => ['booking_id' => 3],
            'occurred_at' => now(),
            'read_at' => $readAt,
            'delivery_status' => 'DELIVERED',
        ]);
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
