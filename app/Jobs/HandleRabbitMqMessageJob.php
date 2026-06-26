<?php

namespace App\Jobs;

use App\Events\RabbitMqMessageReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleRabbitMqMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly array $payload,
        public readonly array $metadata = [],
        public readonly string $rawBody = '',
    ) {
        $this->onQueue((string) config('services.rabbitmq.consumer_job_queue', 'rabbitmq-events'));
    }

    public function handle(): void
    {
        Event::dispatch(new RabbitMqMessageReceived(
            $this->payload,
            $this->metadata,
            $this->rawBody,
        ));

        Log::info('RabbitMQ message processed.', [
            'message_id' => $this->metadata['message_id'] ?? null,
            'correlation_id' => $this->metadata['correlation_id'] ?? null,
            'queue' => $this->metadata['queue'] ?? null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RabbitMQ message job failed.', [
            'message_id' => $this->metadata['message_id'] ?? null,
            'correlation_id' => $this->metadata['correlation_id'] ?? null,
            'queue' => $this->metadata['queue'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
