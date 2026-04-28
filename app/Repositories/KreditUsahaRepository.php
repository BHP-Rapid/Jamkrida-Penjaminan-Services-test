<?php

namespace App\Repositories;

use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturPaymentGateway;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\MultigunaDebitur;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TrxDebiturDefaultBase;
use App\Models\v2\KreditUsahaTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KreditUsahaRepository
{
    public function getPenjaminanTransaction($trx_no)
    {
        return PenjaminanTransaction::join('kredit_usaha_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
    }
    public function getInstitution($id_kredit_usaha_transaction)
    {
        return DB::table('institution as a')
            ->join('trx_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.kredit_usaha_trx_id', $id_kredit_usaha_transaction)
            ->select('b.*', 'a.*')
            ->get();
    }
    public function getLampiranDtl($trx_no)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();
    }
    public function getFlow($trx_no)
    {
        return PenjaminanFlow::where('trx_no', $trx_no)->orderBy('created_at', 'desc')->get();
    }
    public function lampiranData($lampiranLatest)
    {
        return DB::table('setting_hdr as a')
            ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
            ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
            ->leftJoinSub($lampiranLatest, 'lt', function ($join) {
                $join->on('lt.lampiran_id', '=', 'c.value');
            })
            ->select(
                'c.value',
                'c.label',
                'c.option2',
                'lt.file_name',
                'lt.file_info',
                'lt.is_additional',
                'lt.status_doc',
                'lt.mime_type'
            )
            ->where('a.module', 'PENJAMINAN_SETTINGS')
            ->where('b.product_id', 'cstb')
            ->where('a.mitra_id', 'MDR')
            ->where('b.is_mandatory', 1)
            ->where('c.key', 'lampiran')
            ->orderBy('c.value', 'asc')
            ->get();
    }

    public function getLatestTrxNoByPeriod(string $year, string $month): ?string
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', 'PNJ-' . $year . '-' . $month . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function createPenjaminanTransaction(array $payload): void
    {
        PenjaminanTransaction::create($payload);
    }
    public function createKUTransaction(array $payload): KreditUsahaTransaction
    {
        return KreditUsahaTransaction::create($payload);
    }
    public function getNowJakarta(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }
    public function getLatestLoanNumber(string $prefix): ?string
    {
        return MultigunaDebitur::lockForUpdate()
            ->where('loan_number', 'like', $prefix . '%')
            ->orderBy('loan_number', 'desc')
            ->value('loan_number');
    }
    public function insertInstitutions(array $rows): void
    {
        if (!empty($rows)) {
            Institution::insert($rows);
        }
    }
    public function insertTrxDebiturDefaultBase(array $rows): void
    {
        if (!empty($rows)) {
            TrxDebiturDefaultBase::insert($rows);
        }
    }
    public function insertLampiranDetails(array $rows): void
    {
        if (!empty($rows)) {
            DB::table('penjaminan_lampiran_dtl')->insert($rows);
        }
    }
    public function createPenjaminanFlow(array $payload): void
    {
        PenjaminanFlow::create($payload);
    }

    public function checkPenjaminanTransaction($trxNo): PenjaminanTransaction
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)->first();
    }

    public function updatePenjaminanTransaction($trxNo, array $payload): PenjaminanTransaction
    {
        $penjaminanTrx = PenjaminanTransaction::where('trx_no', $trxNo)->first();
        $penjaminanTrx->update($payload);
        return $penjaminanTrx;
    }

    public function updateKreditUsahaTransaction($trxNo, array $payload): KreditUsahaTransaction
    {
        $kreditUsahaTrx = KreditUsahaTransaction::where('trx_no', $trxNo)->first();
        $kreditUsahaTrx->update($payload);
        return $kreditUsahaTrx;
    }

    public function checkTrxDebiturDefaultBase($prefix)
    {
        return TrxDebiturDefaultBase::lockForUpdate()
            ->where('loan_number', 'like', $prefix . '%')
            ->orderBy('loan_number', 'desc')
            ->value('loan_number');
    }

    public function getHeader($trx_no, $no_surat_permohonan, $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('kredit_usaha_transaction as kut', 'tph.trx_no', '=', 'kut.trx_no')
            ->join('trx_debitur as td', 'kut.id_kredit_usaha_transaction', '=', 'td.kredit_usaha_trx_id')
            ->join('debitur_tenor_schedule as dts', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
            ->where('tph.trx_no', $trx_no)
            ->where('dts.status', 'Pending')
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'kut.id_kredit_usaha_transaction',
                'td.id_trx_debitur',
                'td.plafond_kredit',
                // 'td.nik',
                'td.nama_nasabah',
                'dts.amount',
                'dts.invoice_number',
                'dts.due_date',
                'dts.status'
            ])
            ->get();
    }

    public function getHeaderList($trx_no, $no_surat_permohonan, $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('kredit_usaha_transaction as kut', 'tph.trx_no', '=', 'kut.trx_no')
            ->where('tph.trx_no', $trx_no)
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'tph.*',
                'kut.id_kredit_usaha_transaction',
            ])
            ->first();
    }

    public function getUnpaid($trx_no)
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

    public function dataDebitur($id_kredit_usaha_transaction)
    {
        return TrxDebiturDefaultBase::where('kredit_usaha_trx_id', $id_kredit_usaha_transaction)
            ->select(
                'id_trx_debitur',
                'no_sp_detail',
                'loan_number',
                'tanggal_realisasi',
                'nama_nasabah'
            )
            ->orderBy('id_trx_debitur', 'asc')
            ->get();
    }

    public function schedules($debiturIds)
    {
        return DebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->WhereIn('status', ['Unpaid', 'Pending'])
            ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
            ->orderBy('tenor_sequence', 'asc')
            ->get();
    }

    public function scheduleUnpaid($debiturIds)
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
            // ->where('debitur_invoice_header.invoice_scope', '=', 'Merge Payment')
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

    public function tenorData($arrInvoiceNoTemp, $trx_no)
    {
        return DebiturTenorSchedule::query()
            ->from('kredit_usaha_transaction as kut')
            ->join('trx_debitur as td', 'td.kredit_usaha_trx_id', '=', 'kut.id_kredit_usaha')
            ->join('debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur')
            ->select([
                'kut.id_kredit_usaha',
                'dts.schedule_id',
                'dts.id_trx_debitur',
                'dts.tenor_sequence',
                'dts.invoice_number',
                'dts.amount',
                'td.id_trx_debitur',
                'td.no_sp_detail',
            ])
            ->whereIn('dts.invoice_number', $arrInvoiceNoTemp)
            ->where('kut.trx_no', $trx_no)
            ->get();
    }

    public function mltHeader($trx_no)
    {
        return PenjaminanTransaction::where('trx_no', $trx_no)
            ->select('no_surat_permohonan')->first();
    }

    public function createDebiturInvoiceHeader(array $payload): DebiturInvoiceHeader
    {
        return DebiturInvoiceHeader::create($payload);
    }

    public function createDebiturPaymentGateway(array $payload)
    {
        return DebiturPaymentGateway::create($payload);
    }

    public function updateDebiturTenorSchedule($schedule_id, array $payload)
    {
        DebiturTenorSchedule::where('schedule_id', $schedule_id)
            ->update($payload);
    }

    public function createPenjaminanLampiranDtl(array $payload){
        PenjaminanLampiranDtl::create($payload);
    }
}
