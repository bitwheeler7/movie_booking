<?php

namespace App\Console\Commands;

use App\Services\NotificationEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Facades\Kafka;
use Throwable;

class ConsumeNotifications extends Command
{
    protected $signature = 'kafka:consume-notifications';

    protected $description = 'Consume movie booking notification events';

    public function handle(): int
    {
        $consumer = Kafka::consumer([
            'booking.confirmed',
            'booking.cancelled',
            'payment.succeeded',
            'payment.failed',
            'payment.refunded',
        ])
            ->withConsumerGroupId('notification-service-group')
            ->withManualCommit()
            ->withHandler(function (
                ConsumerMessage $message,
                MessageConsumer $consumer
            ) {
                $topic = $message->getTopicName();

                try {
                    $event = json_decode(
                        $message->getBody(),
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );

                    if (! is_array($event)) {
                        throw new \InvalidArgumentException('Notification event must be a JSON object.');
                    }
                } catch (JsonException|\InvalidArgumentException $e) {
                    $this->sendToDeadLetterTopic($message, $e);
                    $consumer->commit($message);
                    Log::warning('Malformed Kafka notification sent to dead-letter topic', [
                        'topic' => $topic,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                try {
                    $notification = null;
                    for ($attempt = 1; $attempt <= 3; $attempt++) {
                        try {
                            $notification = app(NotificationEventRecorder::class)
                                ->record($event, $topic);
                            break;
                        } catch (QueryException $e) {
                            if ($attempt === 3) {
                                throw $e;
                            }

                            usleep(100_000 * $attempt);
                        }
                    }

                    // Commit only after database processing succeeds.
                    $consumer->commit($message);

                    $this->info('Notification processed: '.$notification->event_id);
                } catch (ValidationException|\InvalidArgumentException $e) {
                    $this->sendToDeadLetterTopic($message, $e);
                    $consumer->commit($message);
                    Log::warning('Invalid Kafka notification sent to dead-letter topic', [
                        'topic' => $topic,
                        'error' => $e->getMessage(),
                    ]);
                } catch (Throwable $e) {
                    Log::error('Kafka notification processing failed', [
                        'topic' => $topic,
                        'error' => $e->getMessage(),
                    ]);

                    throw $e;
                }
            })
            ->build();

        $this->info('PHP Kafka notification consumer started.');

        $consumer->consume();

        return self::SUCCESS;
    }

    private function sendToDeadLetterTopic(ConsumerMessage $message, Throwable $error): void
    {
        Kafka::publish()
            ->onTopic(config('services.notification.dead_letter_topic'))
            ->withBody([
                'source_topic' => $message->getTopicName(),
                'raw_payload' => $message->getBody(),
                'error' => substr($error->getMessage(), 0, 2000),
                'failed_at' => now()->toIso8601String(),
            ])
            ->send();
    }
}
