<?php

namespace App\Repositories;

use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturPaymentGateway;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\MultigunaTrxKreditMikroKecil;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TrxDebiturDefaultBase;
use Illuminate\Support\Facades\DB;

class KreditMikroKecilRepository
{
    public function getPendingTenorSchedule(
        string $trxNo,
        string $noSuratPermohonan,
        bool $isSplit
    ) {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kredit_mikro_kecil as mtkmk', 'tph.trx_no', '=', 'mtkmk.trx_no')
            ->join('trx_debitur as td', 'mtkmk.id_multiguna_kredit_mikro_kecil', '=', 'td.kredit_mikro_trx_id')
            ->join('debitur_tenor_schedule as dts', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
            ->Join('institution as i', 'i.institution_id', '=', 'td.institution_id')
            ->where('tph.trx_no', $trxNo)
            ->where('dts.status', 'Pending')
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'mtkmk.id_multiguna_kredit_mikro_kecil',
                'td.id_trx_debitur',
                'td.plafond_kredit',
                'i.id_number as nik',
                'td.nama_nasabah as debitur_name',
                'dts.amount',
                'dts.invoice_number',
                'dts.due_date',
                'dts.status'
            ])
            ->get();
    }

    public function getKmkDebitur(int $multigunaId)
    {
        return Institution::query()
            ->from('institution as a')
            ->join('trx_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.id_trx_debitur', $multigunaId)
            ->select('b.*', 'a.*')
            ->get();
    }

    public function getKMKLampiran(string $trxNo)
    {
        $query = PenjaminanLampiranDtl::where('trx_no', $trxNo)->get();
        return $query;
    }

    public function getKMKFlow(string $trxNo)
    {
        $query = PenjaminanFlow::where('trx_no', $trxNo)->orderBy('created_at', 'desc')->get();
        return $query;
    }


    public function getUnpaidData(string $trx_no)
    {
        return DebiturInvoiceHeader::query()
            ->from('debitur_invoice_header as dih')
            ->join('debitur_tenor_schedule as dts', 'dih.invoice_id', '=', 'dts.invoice_id')
            ->join('debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'dih.invoice_id')
            ->join('trx_debitur as td', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
            ->where('dih.trx_no', $trx_no)
            ->where('dih.status', 'Unpaid')
            ->select(
                'dpg.payment_id',
                'dih.invoice_id',
                'dpg.order_id',
                'dpg.order_payment_url',
                'dpg.order_payment_token',
                'dts.tenor_sequence',
                'dih.trx_no',
                'dih.total_amount',
                DB::raw('COUNT(td.id_trx_debitur) AS total_debitur')
            )
            ->groupBy(
                'dpg.payment_id',
                'dpg.order_id',
                'dpg.order_payment_url',
                'dts.tenor_sequence',
                'dih.trx_no',
                'dih.total_amount'
            )->get();
    }


    public function getHeader(string $trxNo, string $noSuratPermohonan, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kredit_mikro_kecil as mtkmk', 'tph.trx_no', '=', 'mtkmk.trx_no')
            ->where('tph.trx_no', $trxNo)
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'tph.*',
                'mtkmk.id_multiguna_kredit_mikro_kecil',
            ])
            ->first();
    }

    public function getDebiturByKreditId(int $kreditId)
    {
        return TrxDebiturDefaultBase::join('institution as i', 'i.institution_id', '=', 'trx_debitur.institution_id')
            ->where('kredit_mikro_trx_id', $kreditId)
            ->select(
                'trx_debitur.id_trx_debitur',
                'trx_debitur.no_sp_detail',
                'trx_debitur.loan_number',
                'trx_debitur.tanggal_realisasi',
                'trx_debitur.nama_nasabah',
                'i.id_number as nik'
            )
            ->orderBy('trx_debitur.id_trx_debitur', 'asc')
            ->get();
    }


    public function getSchedules(array $debiturIds)
    {
        return DebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->whereIn('status', ['Unpaid', 'Pending'])
            ->select(
                'id_trx_debitur',
                'tenor_sequence',
                'amount',
                'due_date',
                'status',
                'invoice_number'
            )
            ->orderBy('tenor_sequence', 'asc')
            ->get();
    }

    public function getTenorData(string $trxNo, array $arrInvoiceNoTemp)
    {
        return MultigunaTrxKreditMikroKecil::query()
            ->from('multiguna_trx_kredit_mikro_kecil as mtkmk')
            ->join('trx_debitur as td', 'td.kredit_mikro_trx_id', '=', 'mtkmk.id_multiguna_kredit_mikro_kecil')
            ->join('debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur')
            ->select([
                'mtkmk.id_kredit_usaha',
                'dts.schedule_id',
                'dts.id_trx_debitur',
                'dts.tenor_sequence',
                'dts.invoice_number',
                'dts.amount',
                'td.id_trx_debitur',
                'td.no_sp_detail',
            ])
            ->whereIn('dts.invoice_number', $arrInvoiceNoTemp)
            ->where('mtkmk.trx_no', $trxNo)
            ->get();
    }

    public function headerData(string $trxNo)
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)
            ->select('no_surat_permohonan')
            ->first();
    }

    public function getUnpaidSchedules(array $debiturIds)
    {
        return DebiturInvoiceHeader::select(
            'dpg.payment_id',
            'dpg.order_id',
            'dpg.order_payment_url',
            'dpg.order_payment_token',
            'dts.tenor_sequence',
            'debitur_invoice_header.trx_no',
            'debitur_invoice_header.total_amount',
            DB::raw('COUNT(td.id_trx_debitur) as total_debitur')
        )
            ->join('debitur_tenor_schedule as dts', 'debitur_invoice_header.invoice_id', '=', 'dts.invoice_id')
            ->join('debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'debitur_invoice_header.invoice_id')
            ->join('trx_debitur as td', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
            ->where('dts.status', 'Unpaid')
            ->whereIn('dts.id_trx_debitur', $debiturIds)
            ->groupBy(
                'dpg.order_id',
                'dpg.order_payment_token',
                'dpg.order_payment_url',
                'dts.tenor_sequence',
                'debitur_invoice_header.trx_no',
                'debitur_invoice_header.total_amount'
            )
            ->get();
    }


    public function getKmkDetail(string $trxNo)
    {
        $query = PenjaminanTransaction::join('multiguna_trx_kredit_mikro_kecil as mtkmk', 'transaction_penjaminan_header.trx_no', '=', 'mtkmk.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
            ->select('transaction_penjaminan_header.*', 'mtkmk.*')
            ->first();

        return $query;
    }

    public function CreateDebiturInvoiceHeader(array $data)
    {
        return DebiturInvoiceHeader::create($data);
    }

    public function createDebiturPaymentGateway(array $data)
    {
        return DebiturPaymentGateway::create($data);
    }

    public function createLampiranDtl(array $data)
    {
        return PenjaminanLampiranDtl::create($data);
    }

    public function updateInvoiceAndStatusByScheduleIds(
        array $scheduleIds,
        int $invoiceId,
        string $status = 'Paid'
    ) {
        return DebiturTenorSchedule::whereIn('schedule_id', $scheduleIds)
            ->update([
                'invoice_id' => $invoiceId,
                'status' => $status
            ]);
    }
}
