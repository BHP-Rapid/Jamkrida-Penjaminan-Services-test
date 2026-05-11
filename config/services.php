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

];
