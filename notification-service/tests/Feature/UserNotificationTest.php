<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use App\Services\NotificationEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
    }

    public function test_event_payload_is_persisted_and_duplicate_event_ids_are_not_duplicated(): void
    {
        $event = [
            'event_id' => 'event-123',
            'event_type' => 'booking.confirmed',
            'topic' => 'booking.confirmed',
            'user_id' => 42,
            'title' => 'Booking Confirmed',
            'message' => 'Your booking is confirmed.',
            'data' => [
                'booking_id' => 7,
            ],
            'occurred_at' => now()->toIso8601String(),
        ];

        $recorder = app(NotificationEventRecorder::class);
        $recorder->record($event, 'booking.confirmed');
        $recorder->record($event, 'booking.confirmed');

        $this->assertDatabaseCount('user_notifications', 1);
        $notification = UserNotification::firstOrFail();
        $this->assertSame('booking.confirmed', $notification->topic);
        $this->assertSame(42, $notification->user_id);
        $this->assertSame($event, $notification->data);
        $this->assertSame('Booking Confirmed', $notification->title);
        $this->assertNotNull($notification->occurred_at);
    }

    public function test_event_topic_must_match_the_event_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(NotificationEventRecorder::class)->record([
            'event_id' => 'event-456',
            'event_type' => 'booking.confirmed',
            'user_id' => 42,
            'data' => [],
        ], 'payment.succeeded');
    }
}
