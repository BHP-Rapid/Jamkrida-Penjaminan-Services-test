<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Handlers;

use App\Models\AjpDebiturPaymentGateway;
use App\Models\DebiturPaymentGateway;
use Carbon\CarbonInterface;
use RuntimeException;

class DefaultPaymentHandler implements PaymentHandlerInterface
{
    public function create(mixed $invoiceFP, int|float $ijp, string $orderId, string $snapToken, string $combineToken, CarbonInterface $nowJakarta): void
    {
        DebiturPaymentGateway::create([
            'invoice_id'          => $invoiceFP->invoice_id,
            'status'              => 'Pending',
            'payment_amount_ijp'  => $ijp,
            'order_id'            => $orderId,
            'order_payment_token' => $snapToken,
            'order_payment_url'   => $combineToken,
            'created_at'          => $nowJakarta,
            'updated_at'          => null,
        ]);
    }

    public function update(mixed $invoiceFP,  int|float $ijp, string $orderId, string $snapToken, string $combineToken, CarbonInterface $nowJakarta): void
    {
        $payment = DebiturPaymentGateway::where('invoice_id', $invoiceFP->invoice_id)
            ->orderByDesc('payment_id')
            ->first();

        if (!$payment) {
            throw new RuntimeException(
                'Payment record not found (Default Base).',
                404
            );
        }

        $payment->update([
            'payment_amount_ijp'  => $ijp,
            'order_id'            => $orderId,
            'order_payment_token' => $snapToken,
            'order_payment_url'   => $combineToken,
            'expiry_date_time'    => null,
            'updated_at'          => $nowJakarta,
        ]);
    }
}
