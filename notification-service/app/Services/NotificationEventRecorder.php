<?php

namespace App\Services;

use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class NotificationEventRecorder
{
    private const EVENT_TYPES = [
        'booking.confirmed',
        'booking.cancelled',
        'payment.succeeded',
        'payment.failed',
        'payment.refunded',
    ];

    public function record(array $event, string $topic): UserNotification
    {
        $validated = Validator::make($event, [
            'event_id' => ['required', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'in:'.implode(',', self::EVENT_TYPES)],
            'user_id' => ['required', 'integer', 'min:1'],
            'data' => ['sometimes', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:65535'],
        ])->validate();

        if ($validated['event_type'] !== $topic) {
            throw new InvalidArgumentException('Event type does not match Kafka topic.');
        }

        [$defaultTitle, $defaultMessage] = $this->notificationContent($validated);

        return DB::transaction(fn () => UserNotification::firstOrCreate(
            ['event_id' => $validated['event_id']],
            [
                'event_type' => $validated['event_type'],
                'topic' => $topic,
                'user_id' => $validated['user_id'],
                'title' => $validated['title'] ?? $defaultTitle,
                'message' => $validated['message'] ?? $defaultMessage,
                'data' => $event,
                'occurred_at' => $validated['occurred_at'] ?? null,
                'delivery_status' => 'DELIVERED',
            ]
        ));
    }

    private function notificationContent(array $event): array
    {
        $details = $event['data'] ?? [];
        $reference = $details['booking_reference']
            ?? $details['payment_reference']
            ?? null;

        return match ($event['event_type']) {
            'booking.confirmed' => [
                'Booking confirmed',
                'Your booking'.($reference ? " {$reference}" : '').' has been confirmed.',
            ],
            'booking.cancelled' => [
                'Booking cancelled',
                'Your booking'.($reference ? " {$reference}" : '').' has been cancelled.',
            ],
            'payment.succeeded' => [
                'Payment successful',
                'Your payment'.($reference ? " for {$reference}" : '').' was successful.',
            ],
            'payment.failed' => [
                'Payment failed',
                'Your payment'.($reference ? " for {$reference}" : '').' could not be completed.',
            ],
            'payment.refunded' => [
                'Payment refunded',
                'Your refund'.($reference ? " for {$reference}" : '').' was processed.',
            ],
            default => ['Movie Booking Update', 'Your booking has been updated.'],
        };
    }
}
