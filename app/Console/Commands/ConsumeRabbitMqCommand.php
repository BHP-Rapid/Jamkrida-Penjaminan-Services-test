<?php

namespace App\Console\Commands;

use App\Jobs\HandleRabbitMqMessageJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class ConsumeRabbitMqCommand extends Command
{
    protected $signature = 'rabbitmq:consume
        {queue? : RabbitMQ queue name. Defaults to services.rabbitmq.consumer_queue}
        {--once : Stop after one successfully handled message}
        {--timeout=0 : Seconds to wait for messages; 0 waits forever}
        {--prefetch= : Number of unacked messages allowed at once}
        {--requeue-on-error : Requeue message when handling fails}
        {--dry-run : Decode one message and requeue it without dispatching a job}
        {--dump : Print decoded payload and metadata to console}
        {--declare : Declare the queue before consuming}
        {--bind : Bind the queue to an exchange before consuming}
        {--exchange= : Exchange used with --bind. Defaults to services.rabbitmq.consumer_exchange}
        {--routing-key= : Routing key used with --bind. Defaults to services.rabbitmq.consumer_routing_key}';

    protected $description = 'Consume JSON messages from a RabbitMQ queue and dispatch a Laravel job.';

    private bool $shouldStop = false;

    public function handle(): int
    {
        $queue = $this->queueName();
        $prefetch = (int) ($this->option('prefetch') ?: config('services.rabbitmq.consumer_prefetch_count', 1));
        $requeueOnError = (bool) $this->option('requeue-on-error')
            || (bool) config('services.rabbitmq.consumer_requeue_on_error', false);

        $connection = null;
        $channel = null;

        $this->listenForSignals();

        if ((bool) $this->option('dry-run') && ! (bool) $this->option('once')) {
            $this->error('Use --once with --dry-run so the same requeued message is not consumed in a loop.');

            return self::FAILURE;
        }

        try {
            $connection = $this->connection();
            $channel = $connection->channel();

            if ((bool) $this->option('declare')) {
                $channel->queue_declare($queue, false, true, false, false);
            }

            if ((bool) $this->option('bind')) {
                $exchange = (string) ($this->option('exchange') ?: config('services.rabbitmq.consumer_exchange', ''));
                $routingKey = (string) ($this->option('routing-key') ?: config('services.rabbitmq.consumer_routing_key', $queue));

                $channel->queue_bind($queue, $exchange, $routingKey);
            }

            $channel->basic_qos(0, max(1, $prefetch), false);

            $this->info("Consuming RabbitMQ queue [{$queue}]...");

            $handledMessages = 0;
            $waitTimeout = (int) $this->option('timeout');

            $channel->basic_consume(
                $queue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) use ($queue, $requeueOnError, &$handledMessages): void {
                    if ($this->handleMessage($message, $queue, $requeueOnError)) {
                        $handledMessages++;

                        if ((bool) $this->option('once')) {
                            $this->shouldStop = true;
                        }
                    }
                }
            );

            while ($channel->is_consuming() && ! $this->shouldStop) {
                try {
                    $channel->wait(null, false, $waitTimeout);
                } catch (AMQPTimeoutException) {
                    if ((bool) $this->option('once')) {
                        $this->warn('No RabbitMQ message received before timeout.');
                        break;
                    }
                }
            }

            if ($handledMessages > 0) {
                $this->info("Handled {$handledMessages} RabbitMQ message(s).");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('RabbitMQ consumer stopped with error.', [
                'queue' => $queue,
                'error' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            try {
                $channel?->close();
            } catch (Throwable) {
                //
            }

            try {
                $connection?->close();
            } catch (Throwable) {
                //
            }
        }
    }

    private function handleMessage(AMQPMessage $message, string $queue, bool $requeueOnError): bool
    {
        $rawBody = $message->getBody();
        $metadata = $this->metadata($message, $queue);

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ((bool) $this->option('dry-run')) {
                $this->dumpRawMessage($rawBody, $metadata, $exception->getMessage());
                $message->nack(true);

                return true;
            }

            Log::error('RabbitMQ consumer received invalid JSON.', [
                'queue' => $queue,
                'error' => $exception->getMessage(),
                'metadata' => $metadata,
            ]);

            $message->ack();

            return false;
        }

        if (! is_array($payload)) {
            if ((bool) $this->option('dry-run')) {
                $this->dumpMessage($payload, $metadata);
                $message->nack(true);

                return true;
            }

            Log::error('RabbitMQ consumer received JSON that is not an object or array.', [
                'queue' => $queue,
                'metadata' => $metadata,
            ]);

            $message->ack();

            return false;
        }

        try {
            $this->logIncomingPayloadForTesting($payload, $metadata);

            if ((bool) $this->option('dump')) {
                $this->dumpMessage($payload, $metadata);
            }

            if ((bool) $this->option('dry-run')) {
                Log::info('RabbitMQ dry-run consumed message and requeued it.', [
                    'queue' => $queue,
                    'message_id' => $metadata['message_id'] ?? null,
                    'correlation_id' => $metadata['correlation_id'] ?? null,
                ]);

                $message->nack(true);

                return true;
            }

            HandleRabbitMqMessageJob::dispatch($payload, $metadata, $rawBody)
                ->onQueue((string) config('services.rabbitmq.consumer_job_queue', 'rabbitmq-events'));

            Log::info('RabbitMQ message queued for Laravel processing.', [
                'queue' => $queue,
                'message_id' => $metadata['message_id'] ?? null,
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'type' => $metadata['type'] ?? null,
            ]);

            $this->info(sprintf(
                'Accepted RabbitMQ message from [%s]%s.',
                $queue,
                isset($metadata['message_id']) && $metadata['message_id'] !== null ? ' message_id='.$metadata['message_id'] : ''
            ));

            $message->ack();

            return true;
        } catch (Throwable $exception) {
            Log::error('RabbitMQ message handling failed.', [
                'queue' => $queue,
                'message_id' => $metadata['message_id'] ?? null,
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $this->error('RabbitMQ message handling failed: '.$exception->getMessage());

            $message->nack($requeueOnError);

            return false;
        }
    }

    private function logIncomingPayloadForTesting(array $payload, array $metadata): void
    {
        Log::info('TEMP TEST RabbitMQ incoming payload.', [
            'queue' => $metadata['queue'] ?? null,
            'message_id' => $metadata['message_id'] ?? null,
            'correlation_id' => $metadata['correlation_id'] ?? null,
            'metadata' => $metadata,
            'payload' => $payload,
        ]);

        foreach ($this->groupedDataItems($payload) as $item) {
            Log::info('TEMP TEST RabbitMQ incoming data item.', [
                'queue' => $metadata['queue'] ?? null,
                'message_id' => $metadata['message_id'] ?? null,
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'group_index' => $item['group_index'],
                'item_index' => $item['item_index'],
                'produk' => $item['produk'],
                'cara_bayar' => $item['cara_bayar'],
                'data' => $item['data'],
            ]);
        }
    }

    /**
     * @return array<int, array{group_index: int|string, item_index: int|string, produk: mixed, cara_bayar: mixed, data: mixed}>
     */
    private function groupedDataItems(array $payload): array
    {
        $groups = $payload['payload'] ?? $payload['Data'] ?? null;

        if (! is_array($groups)) {
            return [];
        }

        $items = [];
        foreach ($groups as $groupIndex => $group) {
            if (! is_array($group) || ! is_array($group['Data'] ?? null)) {
                continue;
            }

            foreach ($group['Data'] as $itemIndex => $data) {
                $items[] = [
                    'group_index' => $groupIndex,
                    'item_index' => $itemIndex,
                    'produk' => $group['Produk'] ?? null,
                    'cara_bayar' => $group['CaraBayar'] ?? null,
                    'data' => $data,
                ];
            }
        }

        return $items;
    }
    private function dumpMessage(mixed $payload, array $metadata): void
    {
        $this->line(json_encode([
            'metadata' => $metadata,
            'payload' => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function dumpRawMessage(string $rawBody, array $metadata, string $errorMessage): void
    {
        $this->line(json_encode([
            'metadata' => $metadata,
            'error' => $errorMessage,
            'raw_body' => $rawBody,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function connection(): AMQPStreamConnection
    {
        $cfg = config('services.rabbitmq', []);
        $heartbeat = (int) ($cfg['heartbeat'] ?? 60);
        $readWriteTimeout = (float) ($cfg['read_write_timeout'] ?? max(3, $heartbeat * 2));

        return new AMQPStreamConnection(
            (string) ($cfg['host'] ?? '127.0.0.1'),
            (int) ($cfg['port'] ?? 5672),
            (string) ($cfg['user'] ?? 'guest'),
            (string) ($cfg['password'] ?? 'guest'),
            (string) ($cfg['vhost'] ?? '/'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) ($cfg['connection_timeout'] ?? 3),
            $readWriteTimeout,
            null,
            false,
            $heartbeat,
        );
    }

    private function queueName(): string
    {
        $queue = trim((string) ($this->argument('queue') ?: config('services.rabbitmq.consumer_queue')));

        if ($queue === '') {
            $queue = (string) config('services.rabbitmq.queue', 'integration.in');
        }

        return $queue;
    }

    private function metadata(AMQPMessage $message, string $queue): array
    {
        return [
            'queue' => $queue,
            'delivery_tag' => $message->has('delivery_tag') ? $message->get('delivery_tag') : null,
            'exchange' => $message->has('exchange') ? $message->get('exchange') : null,
            'routing_key' => $message->has('routing_key') ? $message->get('routing_key') : null,
            'redelivered' => $message->has('redelivered') ? $message->get('redelivered') : null,
            'message_id' => $message->has('message_id') ? $message->get('message_id') : null,
            'correlation_id' => $message->has('correlation_id') ? $message->get('correlation_id') : null,
            'type' => $message->has('type') ? $message->get('type') : null,
            'app_id' => $message->has('app_id') ? $message->get('app_id') : null,
            'headers' => $this->headers($message),
        ];
    }

    private function headers(AMQPMessage $message): array
    {
        if (! $message->has('application_headers')) {
            return [];
        }

        $headers = $message->get('application_headers');

        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }

        return is_array($headers) ? $headers : [];
    }

    private function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function (): void {
            $this->shouldStop = true;
        });

        pcntl_signal(SIGINT, function (): void {
            $this->shouldStop = true;
        });
    }
}


