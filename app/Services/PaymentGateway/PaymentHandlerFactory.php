<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway;

use Exception;
use App\Services\PaymentGateway\Handlers\SrtbPaymentHandler;
use App\Services\PaymentGateway\Handlers\MltPaymentHandler;
use App\Services\PaymentGateway\Handlers\CstbPaymentHandler;
use App\Services\PaymentGateway\Handlers\DefaultPaymentHandler;
use App\Services\PaymentGateway\Handlers\PaymentHandlerInterface;

class PaymentHandlerFactory
{
    public static function make(string $product): PaymentHandlerInterface
    {
        return match ($product) {
            'srtb' => new SrtbPaymentHandler(),
            'mlt'  => new MltPaymentHandler(),
            'cstb' => new CstbPaymentHandler(),
            'ku', 'kur', 'kmk' => new DefaultPaymentHandler(),

            default => throw new Exception("Unsupported product: {$product}")
        };
    }
}