<?php

namespace App\Services;

use App\Models\OutboxEvent;
use Illuminate\Support\Str;

class EventOutboxService
{
    public function record(
        string $topic,
        string $eventType,
        int $userId,
        array $data
    ): OutboxEvent {
        $eventId = (string) Str::uuid();

        return OutboxEvent::create([
            'event_id' => $eventId,
            'topic' => $topic,
            'payload' => [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => now()->toIso8601String(),
                'user_id' => $userId,
                'data' => $data,
            ],
            'status' => 'PENDING',
            'available_at' => now(),
        ]);
    }
}
