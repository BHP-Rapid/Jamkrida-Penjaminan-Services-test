<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

class RabbitMQHelper
{
    public static function dispatch(array $message, string $queue, array $options = []): void
    {
        $cfg = config('services.rabbitmq', []);

        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 5672);
        $user = (string) ($cfg['user'] ?? 'guest');
        $password = (string) ($cfg['password'] ?? 'guest');
        $vhost = (string) ($cfg['vhost'] ?? '/');

        $exchange = (string) ($options['exchange'] ?? ($cfg['exchange'] ?? ''));
        $routingKey = (string) ($options['routing_key'] ?? ($options['routingKey'] ?? $queue));
        $persistent = (bool) ($options['persistent'] ?? ($cfg['persistent'] ?? true));

        $connection = null;
        $channel = null;

        try {
            $connection = new AMQPStreamConnection(
                $host,
                $port,
                $user,
                $password,
                $vhost,
            );
            $channel = $connection->channel();

            $body = json_encode($message, JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new RuntimeException('Failed to encode RabbitMQ message to JSON: '.json_last_error_msg());
            }

            $properties = [
                'content_type' => 'application/json',
                'delivery_mode' => $persistent
                    ? AMQPMessage::DELIVERY_MODE_PERSISTENT
                    : AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
            ];

            if (! empty($options['message_id'])) {
                $properties['message_id'] = (string) $options['message_id'];
            }

            if (! empty($options['correlation_id'])) {
                $properties['correlation_id'] = (string) $options['correlation_id'];
            }

            if (! empty($options['type'])) {
                $properties['type'] = (string) $options['type'];
            }

            if (! empty($options['app_id'])) {
                $properties['app_id'] = (string) $options['app_id'];
            }

            if (! empty($options['headers']) && is_array($options['headers'])) {
                $properties['application_headers'] = new AMQPTable($options['headers']);
            }

            $channel->basic_publish(new AMQPMessage($body, $properties), $exchange, $routingKey);
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

    public static function safeDispatch(array $message, string $queue, array $options = []): bool
    {
        try {
            self::dispatch($message, $queue, $options);

            return true;
        } catch (Throwable $exception) {
            Log::error('RabbitMQ dispatch failed', [
                'queue' => $queue,
                'exchange' => $options['exchange'] ?? (config('services.rabbitmq.exchange') ?? null),
                'routing_key' => $options['routing_key'] ?? ($options['routingKey'] ?? null),
                'error' => $exception->getMessage(),
            ]);

            if (config('services.rabbitmq.throw_on_error')) {
                throw $exception;
            }

            return false;
        }
    }
}
