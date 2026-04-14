<?php

declare(strict_types=1);

namespace App\Services\PaymentGateway\Handlers;

use App\Models\TrxSrtbPaymentGateway;
use Carbon\CarbonInterface;
use RuntimeException;

class SrtbPaymentHandler implements PaymentHandlerInterface
{
    public function create(mixed $invoiceFP, int|float $ijp, string $orderId, string $snapToken, string $combineToken, CarbonInterface $nowJakarta): void
    {
        TrxSrtbPaymentGateway::create([
            'srtb_invoice_id'     => $invoiceFP->srtb_invoice_id,
            'status'              => 'Pending',
            'payment_amount_ijp'  => $ijp,
            'order_id'            => $orderId,
            'order_payment_token' => $snapToken,
            'order_payment_url'   => $combineToken,
            'created_at'          => $nowJakarta,
            'updated_at'          => null,
        ]);
    }

    public function update(mixed $invoiceFP, int|float $ijp, string $orderId, string $snapToken, string $combineToken, CarbonInterface $nowJakarta): void
    {
        $payment = TrxSrtbPaymentGateway::where('srtb_invoice_id', $invoiceFP->srtb_invoice_id)
            ->orderByDesc('srtb_payment_id')
            ->first();

        if (!$payment) {
            throw new RuntimeException(
                'Payment record not found (srtb).',
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
