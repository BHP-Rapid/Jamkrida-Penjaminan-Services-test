<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Handlers;

use Carbon\CarbonInterface;

interface PaymentHandlerInterface
{
    /**
     * Create payment gateway record for specific product.
     *
     * @param mixed $invoiceFP
     * @param int|float $ijp
     * @param string $orderId
     * @param string $snapToken
     * @param string $combineToken
     * @param CarbonInterface $nowJakarta
     *
     * @return void
     */
    public function create(
        mixed $invoiceFP,
        int|float $ijp,
        string $orderId,
        string $snapToken,
        string $combineToken,
        CarbonInterface $nowJakarta
    ): void;

    public function update(
        mixed $invoiceFP,
        int|float $ijp,
        string $orderId,
        string $snapToken,
        string $combineToken,
        CarbonInterface $nowJakarta
    ): void;
}
