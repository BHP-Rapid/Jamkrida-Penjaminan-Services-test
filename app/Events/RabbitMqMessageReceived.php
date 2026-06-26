<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class RabbitMqMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly array $payload,
        public readonly array $metadata = [],
        public readonly string $rawBody = '',
    ) {}
}
