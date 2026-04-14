<?php

namespace App\Repositories;

use App\Models\MultigunaTenorSchedule;
use App\Models\NotifMitra;
use App\Models\PenjaminanTransaction;
use App\Models\SuretyBondTenorSchedule;
use App\Models\TrxInvoiceHeader;
use App\Models\TrxPaymentGateway;
use App\Models\TrxSrtbPaymentGateway;
use Carbon\Carbon;
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

    public function getDetailMlt(string $trxNo, string $noSuratPermohonan)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
            ->where('tph.trx_no', $trxNo)
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->select([
                'tph.*',
                'mt.*',
            ])->first();
    }

    public function checkOrderMltById($trxNo, $orderId)
    {
        return TrxInvoiceHeader::join('transaction_payment_gateway as mpg', 'mpg.invoice_id', '=', 'transaction_invoice_header.invoice_id')
            ->Where('trx_no', $trxNo)
            ->where('mpg.order_id', $orderId)
            ->select([
                'transaction_invoice_header.*',
                'mpg.*',
            ])->first();
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


    public function UpdateInvoiceDetailMlt(string $invoiceId, object $getDetailAfterPayment, array $orderStatus, Carbon $nowJakarta): void
    {
        TrxPaymentGateway::where('invoice_id', $invoiceId)
            ->update([
                'expiry_date_time' => $getDetailAfterPayment->expiry_time,
                'status'           => $orderStatus['status'],
                'settlement_time'  => $getDetailAfterPayment->settlement_time,
                'transaction_time' => $getDetailAfterPayment->transaction_time,
                'updated_at'       => $nowJakarta,
            ]);
    }

    public function UpdateInvoiceHeaderMlt(string $invoiceId, array $orderStatus, Carbon $nowJakarta): void
    {
        TrxInvoiceHeader::where('invoice_id', $invoiceId)
            ->update([
                'status'     => $orderStatus['status'],
                'updated_at' => $nowJakarta,
                'is_manual'  => 0,
            ]);
    }

    public function UpdateTenorInvoiceMlt(string $invoiceId, array $orderStatus, Carbon $nowJakarta): void
    {
        MultigunaTenorSchedule::where('invoice_id', $invoiceId)
            ->update([
                'status'     => $orderStatus['status'],
                'updated_at' => $nowJakarta,
            ]);
    }

    public function getListDebiturMlt(string $multigunaTrxId,  ?array $listDebitur, string $orderId)
    {
        $query = MultigunaTenorSchedule::join(
            'multiguna_debitur as md',
            'md.id_trx_debitur',
            '=',
            'multiguna_tenor_schedule.id_trx_debitur'
        )
            ->join(
                'transaction_invoice_header as mih',
                'mih.invoice_id',
                '=',
                'multiguna_tenor_schedule.invoice_id'
            )
            ->join(
                'transaction_payment_gateway as mpg',
                'mpg.invoice_id',
                '=',
                'mih.invoice_id'
            )
            ->join(
                'institution as i',
                'i.institution_id',
                '=',
                'md.institution_id'
            )
            ->where('md.multiguna_trx_id', $multigunaTrxId);

        if (!empty($listDebitur)) {
            $idDebiturArray = array_map(function ($debitur) {
                return $debitur['IdDebitur'];
            }, $listDebitur);
            $query->whereIn('md.id_trx_debitur', $idDebiturArray);
        } else {
            $query->where('mpg.order_id', $orderId);
        }

        return $query->get();
    }

    public function updateNoKwitansiByDebiturIds(array $debiturIds, string $trxName): void
    {
        MultigunaTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->update([
                'no_kwitansi' => $trxName
            ]);
    }
    public function insertNotifications(array $notifications): void
    {
        if (!empty($notifications)) {
            NotifMitra::insert($notifications);
        }
    }
}
