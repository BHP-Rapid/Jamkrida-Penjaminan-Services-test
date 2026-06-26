<?php

namespace App\Listeners;

use App\Events\RabbitMqMessageReceived;
use App\Services\Invoice\ProcessRabbitMqInvoiceService;
use Illuminate\Support\Facades\Log;

class ProcessInvoiceRabbitMqMessage
{
    public function __construct(
        private readonly ProcessRabbitMqInvoiceService $invoiceService,
    ) {}

    public function handle(RabbitMqMessageReceived $event): void
    {
        if (! $this->supports($event->payload)) {
            return;
        }

        $result = $this->invoiceService->process($event->payload);

        Log::info('RabbitMQ invoice payload processed.', array_merge($result, [
            'message_id' => $event->metadata['message_id'] ?? null,
            'correlation_id' => $event->metadata['correlation_id'] ?? null,
            'queue' => $event->metadata['queue'] ?? null,
        ]));
    }

    private function supports(array $payload): bool
    {
        $groups = $payload['payload'] ?? $payload['Data'] ?? null;

        if (! is_array($groups) || $groups === []) {
            return false;
        }

        $firstGroup = reset($groups);

        return is_array($firstGroup)
            && array_key_exists('Produk', $firstGroup)
            && array_key_exists('CaraBayar', $firstGroup)
            && array_key_exists('Data', $firstGroup);
    }
}
