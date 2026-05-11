<?php

namespace App\Repositories;

use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\v2\KonstruksiDebiturInvoiceHeader;
use App\Models\v2\KonstruksiDebiturPaymentGateway;
use App\Models\v2\KonstruksiDebiturTenorSchedule;
use App\Models\v2\MultigunaTrxKonstruksi;
use App\Models\v2\TrxDebiturKonstruksi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class KonstruksiRepository
{
    public function getTenantMitra($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id', 'is_syariah', 'is_conventional')
            ->first();
    }
    public function getPnjTrx(string $trx_no)
    {
        return PenjaminanTransaction::join('multiguna_trx_kreditkonstruksi as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->join('multiguna_trx_kreditkonstruksi', 'transaction_penjaminan_header.trx_no', '=', 'multiguna_trx_kreditkonstruksi.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
    }

    public function getInst($id_multiguna_konstruksi)
    {
        return DB::table('institution as a')
            ->join('trx_debitur_construction as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.id_multiguna_konstruksi', $id_multiguna_konstruksi)
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

    public function getLatestTrxNoByPeriod(string $year, string $month): ?string
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', 'PNJ-' . $year . '-' . $month . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function lockPenjaminan($trxNo)
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', $trxNo)
            ->firstOrFail();
    }

    public function getLatestLoanNumber(string $prefix): ?string
    {
        return TrxDebiturKonstruksi::lockForUpdate()
            ->where('loan_number', 'like', $prefix . '%')
            ->orderBy('loan_number', 'desc')
            ->value('loan_number');
    }

    public function lockMultigunaTrxKonstruksi($trxNo)
    {
        return MultigunaTrxKonstruksi::lockForUpdate()
            ->where('trx_no', $trxNo)
            ->firstOrFail();
    }

    public function insertInstitutions(array $rows): void
    {
        if (!empty($rows)) {
            Institution::insert($rows);
        }
    }

    public function insertKonstruksiDebitur(array $rows): void
    {
        if (!empty($rows)) {
            TrxDebiturKonstruksi::insert($rows);
        }
    }

    public function insertLampiranDetails(array $rows): void
    {
        DB::table('penjaminan_lampiran_dtl')->insert($rows);
    }

    public function deleteLampiranDetails($trxNo)
    {
        return DB::table('penjaminan_lampiran_dtl')->where('trx_no', $trxNo)->delete();
    }

    public function createPenjaminanFlow(array $payload): void
    {
        PenjaminanFlow::create($payload);
    }

    public function createPenjaminanTransaction(array $payload): void
    {
        PenjaminanTransaction::create($payload);
    }

    public function invoiceHeaderData(array $payload): KonstruksiDebiturInvoiceHeader
    {
        return KonstruksiDebiturInvoiceHeader::create($payload);
    }

    public function createKonstruksiTransaction(array $payload): MultigunaTrxKonstruksi
    {
        return MultigunaTrxKonstruksi::create($payload);
    }

    public function createKonstruksiPaymentGateway(array $payload): KonstruksiDebiturPaymentGateway
    {
        return KonstruksiDebiturPaymentGateway::create($payload);
    }

    public function getNowJakarta(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }
    public function updatePenjaminanDraft(PenjaminanTransaction $penjaminan, array $payload): void
    {
        $penjaminan->update($payload);
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
    public function dataHeader($trx_no, $no_surat_permohonan, $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kreditkonstruksi as kut', 'tph.trx_no', '=', 'kut.trx_no')
            ->join('trx_debitur_construction as td', 'kut.id_multiguna_konstruksi', '=', 'td.id_multiguna_konstruksi')
            ->join('konstruksi_debitur_tenor_schedule as dts', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
            ->join('institution as i', 'i.institution_id', '=', 'td.institution_id')
            ->where('tph.trx_no', $trx_no)
            ->where('dts.status', 'Pending')
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'kut.id_multiguna_konstruksi',
                'td.id_trx_debitur_konstruksi',
                'td.nilai_kredit_per_proyek as plafond_kredit',
                'i.id_number as nik',
                'td.nama_proyek as nama_nasabah',
                'dts.amount',
                'dts.invoice_number',
                'dts.due_date',
                'dts.status'
            ])
            ->get();
    }
    public function dataHeaderList($trx_no, $no_surat_permohonan, $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kreditkonstruksi as kut', 'tph.trx_no', '=', 'kut.trx_no')
            ->where('tph.trx_no', $trx_no)
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'tph.*',
                'kut.id_multiguna_konstruksi',
            ])
            ->first();
    }
    public function dataUnpaid($trx_no)
    {
        return KonstruksiDebiturInvoiceHeader::query()
            ->from('konstruksi_debitur_invoice_header as dih')
            // DB::table('konstruksi_debitur_invoice_header as dih')
            ->join('konstruksi_debitur_tenor_schedule as dts', 'dih.invoice_id', '=', 'dts.invoice_id')
            ->join('konstruksi_debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'dih.invoice_id')
            ->join('trx_debitur_construction as td', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
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
                DB::raw('COUNT(td.id_trx_debitur_konstruksi) AS total_debitur')
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
    public function dataDebitur($id_multiguna_konstruksi)
    {
        return TrxDebiturKonstruksi::where('id_multiguna_konstruksi', $id_multiguna_konstruksi)
            ->join('institution as i', 'i.institution_id', '=', 'trx_debitur_construction.institution_id')
            ->select(
                'id_trx_debitur_konstruksi',
                'no_sp_detail',
                'loan_number',
                'tanggal_realisasi',
                'nama_proyek as nama_nasabah',
                'i.id_number as nik',
            )
            ->orderBy('id_trx_debitur_konstruksi', 'asc')
            ->get();
    }

    public function schedule($debiturIds)
    {
        return KonstruksiDebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->WhereIn('status', ['Unpaid', 'Pending'])
            ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
            ->orderBy('tenor_sequence', 'asc')
            ->get();
    }

    public function scheduleUnpaid($debiturIds)
    {
        return KonstruksiDebiturInvoiceHeader::select(
            'dpg.payment_id',
            'dpg.order_id',
            'dpg.order_payment_url',
            'dpg.order_payment_token',
            'dts.tenor_sequence',
            'konstruksi_debitur_invoice_header.trx_no',
            'konstruksi_debitur_invoice_header.total_amount',
            DB::raw('COUNT(td.id_trx_debitur_konstruksi) as total_debitur')
        )
            ->join('konstruksi_debitur_tenor_schedule as dts', 'konstruksi_debitur_invoice_header.invoice_id', '=', 'dts.invoice_id')
            ->join('konstruksi_debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'konstruksi_debitur_invoice_header.invoice_id')
            ->join('trx_debitur_construction as td', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
            // ->where('konstruksi_debitur_invoice_header.invoice_scope', '=', 'Merge Payment')
            ->where('dts.status', 'Unpaid')
            ->whereIn('dts.id_trx_debitur', $debiturIds)
            ->groupBy(
                'dpg.order_id',
                'dpg.order_payment_token',
                'dpg.order_payment_url',
                'dts.tenor_sequence',
                'konstruksi_debitur_invoice_header.trx_no',
                'konstruksi_debitur_invoice_header.total_amount'
            )
            ->get();
    }
    public function tenorData($arrInvoiceNoTemp, $trx_no)
    {
        return DebiturTenorSchedule::query()
            ->from('multiguna_trx_kreditkonstruksi as kut')
            ->join('trx_debitur_construction as td', 'td.id_multiguna_konstruksi', '=', 'kut.id_multiguna_konstruksi')
            ->join('konstruksi_debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur_konstruksi')
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
            ->whereIn('dts.invoice_number', $arrInvoiceNoTemp) //, ['INV-493', 'INV-474'])
            ->where('kut.trx_no', $trx_no)
            ->get();
    }
    public function mltHeader($trx_no)
    {
        return PenjaminanTransaction::where('trx_no', $trx_no)
            ->select('no_surat_permohonan')->first();
    }

    public function createLampiranPembayaran(array $payload): PenjaminanLampiranDtl
    {
        return PenjaminanLampiranDtl::create($payload);
    }
}
