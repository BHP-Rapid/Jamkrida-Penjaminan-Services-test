<?php

namespace App\Repositories;

use App\Models\PenjaminanTransaction;
use App\Models\SuretyBondTenorSchedule;
use App\Models\TrxPaymentGateway;
use App\Models\TrxSrtbPaymentGateway;
use Illuminate\Support\Facades\DB;

class PaymentgatewayRepository
{
    //
    public function getDetailSrtb(string $trxNo, string $noSuratPermohonan)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('surety_bond_transaction as sbt', 'sbt.trx_no', '=', 'tph.trx_no')
            ->join('institution as inst', 'sbt.id_institution', '=', 'inst.id')
            ->where('tph.trx_no', $trxNo)
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->select([
                'tph.*',
                'sbt.*',
                'inst.*'
            ])
            ->first();
    }

    public function getTenorByProductId(string $productId)
    {
        return SuretyBondTenorSchedule::where('id_trx_product', $productId)->first();
    }

    public function getLastPaymentByInvoiceId(string $invoiceId)
    {
        return TrxSrtbPaymentGateway::where('srtb_invoice_id', $invoiceId)->orderByDesc('srtb_payment_id')->first();
    }

    public function cancelPaymentMlt(string $orderId)
    {
        return TrxPaymentGateway::where('order_id', $orderId)->orderByDesc('srtb_payment_id')->first();
    }

    public function getInvoiceIdsByOrderId(string $orderId): array
    {
        return TrxPaymentGateway::where('order_id', $orderId)
            ->pluck('invoice_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function resetMltScheduleStatus(string $orderId, $nowJakarta): void
    {
        DB::table('multiguna_tenor_schedule as mts')
            ->join('transaction_payment_gateway as mpg', 'mpg.invoice_id', '=', 'mts.invoice_id')
            ->where('mpg.order_id', $orderId)
            ->update([
                'mts.status' => 'Pending',
                'mts.updated_at' => $nowJakarta,
            ]);
    }
    public function deletePaymentGatewayByOrderId(string $orderId): void
    {
        TrxPaymentGateway::where('order_id', $orderId)->delete();
    }
    public function deleteInvoiceHeaderByInvoiceIds(array $invoiceIds): void
    {
        DB::table('transaction_invoice_header')
            ->whereIn('invoice_id', $invoiceIds)
            ->delete();
    }
}
