<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'secure' => [
        'key' => env('AES_SECRET_KEY', 'bQ6Gs0OjSN0q119PYytRlYMvQ+3Ue71wTEa/0ldiH3M='),
        'hash_key' => env('SECURE_HASH_KEY'),

    ],
    'auth_internal' => [
        'url' => env('AUTH_SERVICE_URL', 'http://localhost:8000'),
        'token' => env('AUTH_SERVICE_TOKEN'),
        'timeout' => env('AUTH_SERVICE_TIMEOUT', 10),
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],
    'core' => [
        'access_key' => env('CORE_ACCESS_KEY'),
    ],
    'creatio' => [
        'url' => env('CREATIO_URL'),
        'username' => env('CREATIO_USERNAME'),
        'password' => env('CREATIO_PASSWORD'),
    ],

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'integration.exchange'),
        'queue' => env('RABBITMQ_QUEUE', 'integration.in'),
        'routing_key' => env('RABBITMQ_ROUTING_KEY', 'in'),
        'consumer_queue' => env('RABBITMQ_CONSUMER_QUEUE', 'integration.out'),
        'consumer_exchange' => env('RABBITMQ_CONSUMER_EXCHANGE', env('RABBITMQ_EXCHANGE', 'integration.exchange')),
        'consumer_routing_key' => env('RABBITMQ_CONSUMER_ROUTING_KEY', 'out'),
        'consumer_prefetch_count' => env('RABBITMQ_CONSUMER_PREFETCH_COUNT', 1),
        'consumer_requeue_on_error' => env('RABBITMQ_CONSUMER_REQUEUE_ON_ERROR', false),
        'consumer_job_queue' => env('RABBITMQ_CONSUMER_JOB_QUEUE', 'rabbitmq-events'),
        'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 3),
        'read_write_timeout' => env('RABBITMQ_READ_WRITE_TIMEOUT', 130),
        'heartbeat' => env('RABBITMQ_HEARTBEAT', 60),
        'persistent' => env('RABBITMQ_PERSISTENT', true),
        'throw_on_error' => env('RABBITMQ_THROW_ON_ERROR', false),
    ],

    'file_internal' => [
        'url' => env('FILE_SERVICE_URL', 'http://localhost:8000'),
    ],
];
