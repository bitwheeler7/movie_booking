<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Throwable;

class PublishOutboxEvents extends Command
{
    protected $signature = 'outbox:publish';

    protected $description = 'Publish pending events to Kafka';

    public function handle(): int
    {
        $maxAttempts = 5;

        while (true) {
            $event = DB::transaction(function () {
                $row = OutboxEvent::where(function ($query) {
                    $query->where('status', 'PENDING')
                        ->orWhere(function ($query) {
                            $query->where('status', 'PROCESSING')
                                ->where('locked_at', '<', now()->subMinutes(5));
                        });
                })
                    ->where(function ($query) {
                        $query->whereNull('available_at')
                            ->orWhere('available_at', '<=', now());
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    return null;
                }

                $row->update([
                    'status' => 'PROCESSING',
                    'locked_at' => now(),
                ]);

                return $row;
            });

            if (! $event) {
                break;
            }

            try {
                Kafka::publish()
                    ->onTopic($event->topic)
                    ->withBody($event->payload)
                    ->send();

                $event->update([
                    'status' => 'PUBLISHED',
                    'published_at' => now(),
                    'locked_at' => null,
                    'last_error' => null,
                ]);

                $this->info("Published: {$event->event_id}");
            } catch (Throwable $e) {
                $attempts = $event->attempts + 1;
                $delays = [5, 30, 120, 300, 600];

                $event->update([
                    'attempts' => $attempts,
                    'status' => $attempts >= $maxAttempts ? 'DEAD' : 'PENDING',
                    'available_at' => $attempts >= $maxAttempts
                        ? null
                        : now()->addSeconds($delays[$attempts - 1]),
                    'locked_at' => null,
                    'last_error' => substr($e->getMessage(), 0, 60000),
                ]);

                Log::error('Kafka outbox publishing failed', [
                    'event_id' => $event->event_id,
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
