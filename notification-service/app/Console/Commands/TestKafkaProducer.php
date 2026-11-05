<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class TestKafkaProducer extends Command
{
    protected $signature = 'kafka:test-producer';

    protected $description = 'Publish a test booking event';

    public function handle(): int
    {
        $event = [
            'event_id' => 'TEST-EVENT-' . uniqid(),
            'event_type' => 'booking.confirmed',
            'user_id' => 1,
            'title' => 'Booking Confirmed',
            'message' => 'Your movie booking has been confirmed.',
            'booking_id' => 1,
            'booking_reference' => 'BK-TEST-001',
        ];

        $message = new Message(
            body: $event,
            key: $event['event_id']
        );

        Kafka::publish(config('kafka.brokers'))
            ->onTopic('booking.confirmed')
            ->withMessage($message)
            ->send();

        $this->info('Test event published successfully.');

        return self::SUCCESS;
    }
}
