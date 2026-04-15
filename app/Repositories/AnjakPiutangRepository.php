<?php

namespace App\Repositories;

use App\Models\AjpDebiturInvoiceHeader;
use App\Models\AjpDebiturPaymentGateway;
use App\Models\AjpDebiturTenorSchedule;
use App\Models\Institution;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\v2\MultigunaTrxAjpModel;
use App\Models\v2\TrxDebiturAjpModel;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class AnjakPiutangRepository
{
    public function getAjpDetail(string $trxNo)
    {
        return PenjaminanTransaction::join('multiguna_trx_ajp as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
            ->where('transaction_penjaminan_header.product', 'ajp')
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
    }

    public function getAjpDebitur(int $idMultigunaAjp)
    {
        return DB::table('institution as a')
            ->join('trx_debitur_ajp as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.id_multiguna_ajp', $idMultigunaAjp)
            ->select('b.*', 'a.*')
            ->get();
    }

    public function getAjpLampiran(string $trxNo)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trxNo)->get();
    }

    public function getAjpFlow(string $trxNo)
    {
        return PenjaminanFlow::where('trx_no', $trxNo)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findPenjaminanForUpdate(string $trxNo)
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', $trxNo)
            ->where('product', 'ajp')
            ->first();
    }

    public function findAjpTransactionForUpdate(string $trxNo)
    {
        $row = MultigunaTrxAjpModel::lockForUpdate()
            ->where('trx_no', $trxNo)
            ->first();

        if (!$row) {
            throw new Exception('Data transaksi AJP tidak ditemukan.');
        }

        return $row;
    }

    public function updatePenjaminanTransaction(PenjaminanTransaction $penjaminan, array $payload): void
    {
        $penjaminan->update($payload);
    }

    public function updateAjpTransaction(MultigunaTrxAjpModel $ajpTrx, array $payload): void
    {
        $ajpTrx->update($payload);
    }

    public function getLatestLoanNumberByPrefix(string $prefix): ?string
    {
        return TrxDebiturAjpModel::lockForUpdate()
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

    public function insertDebitur(array $rows): void
    {
        if (!empty($rows)) {
            TrxDebiturAjpModel::insert($rows);
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

    public function findMitraAlias(?string $mitraId): ?string
    {
        if (empty($mitraId)) {
            return null;
        }

        return TenantMitra::where('mitra_id', $mitraId)->value('alias');
    }

    public function getNowJakarta(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }

    public function getDetailPaymentPending(string $trxNo, string $noSuratPermohonan, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_ajp as ajt', 'tph.trx_no', '=', 'ajt.trx_no')
            ->join('trx_debitur_ajp as td', 'ajt.id_multiguna_ajp', '=', 'td.id_multiguna_ajp')
            ->join('ajp_tenor_schedule as ats', 'td.id_trx_debitur_ajp', '=', 'ats.id_trx_debitur')
            ->join('institution as inst', 'td.institution_id', '=', 'inst.institution_id')
            ->where('tph.trx_no', $trxNo)
            ->where('ats.status', 'Pending')
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'ajt.id_multiguna_ajp',
                'td.id_trx_debitur_ajp',
                'td.plafond_kredit',
                'inst.id_number as nik',
                'td.nama_nasabah',
                'ats.amount',
                'ats.invoice_number',
                'ats.due_date',
                'ats.status',
            ])
            ->get();
    }

    public function getDetailPaymentUnpaid(string $trxNo)
    {
        return AjpDebiturInvoiceHeader::query()
            ->from('ajp_invoice_header as dih')
            ->join('ajp_tenor_schedule as ats', 'dih.invoice_id', '=', 'ats.invoice_id')
            ->join('ajp_payment_gateway as apg', 'apg.invoice_id', '=', 'dih.invoice_id')
            ->join('trx_debitur_ajp as td', 'td.id_trx_debitur_ajp', '=', 'ats.id_trx_debitur')
            ->where('dih.trx_no', $trxNo)
            ->where('dih.status', 'Unpaid')
            ->select(
                'apg.payment_id',
                'dih.invoice_id',
                'apg.order_id',
                'apg.order_payment_url',
                'apg.order_payment_token',
                'ats.tenor_sequence',
                'dih.trx_no',
                'dih.total_amount',
                DB::raw('COUNT(td.id_trx_debitur_ajp) AS total_debitur')
            )
            ->groupBy(
                'apg.payment_id',
                'dih.invoice_id',
                'apg.order_id',
                'apg.order_payment_url',
                'apg.order_payment_token',
                'ats.tenor_sequence',
                'dih.trx_no',
                'dih.total_amount'
            )
            ->get();
    }

    public function getDetailListPaymentHeader(string $trxNo, string $noSuratPermohonan, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_ajp as ajt', 'tph.trx_no', '=', 'ajt.trx_no')
            ->where('tph.trx_no', $trxNo)
            ->where('tph.no_surat_permohonan', $noSuratPermohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'tph.*',
                'ajt.id_multiguna_ajp',
            ])
            ->first();
    }

    public function getDebiturForPaymentList(int $idMultigunaAjp)
    {
        return TrxDebiturAjpModel::where('id_multiguna_ajp', $idMultigunaAjp)
            ->select(
                'id_trx_debitur_ajp as id_trx_debitur',
                'no_sp_detail',
                'loan_number',
                'tanggal_realisasi',
                'nama_nasabah'
            )
            ->orderBy('id_trx_debitur', 'asc')
            ->get();
    }

    public function getTenorSchedulesByDebiturIds(array $debiturIds)
    {
        return AjpDebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->whereIn('status', ['Unpaid', 'Pending'])
            ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
            ->orderBy('tenor_sequence', 'asc')
            ->get();
    }

    public function getUnpaidSchedulesByDebiturIds(array $debiturIds)
    {
        return AjpDebiturInvoiceHeader::select(
            'apg.payment_id',
            'apg.order_id',
            'apg.order_payment_url',
            'apg.order_payment_token',
            'ats.tenor_sequence',
            'ajp_invoice_header.trx_no',
            'ajp_invoice_header.total_amount',
            DB::raw('COUNT(td.id_trx_debitur_ajp) as total_debitur')
        )
            ->join('ajp_tenor_schedule as ats', 'ajp_invoice_header.invoice_id', '=', 'ats.invoice_id')
            ->join('ajp_payment_gateway as apg', 'apg.invoice_id', '=', 'ajp_invoice_header.invoice_id')
            ->join('trx_debitur_ajp as td', 'td.id_trx_debitur_ajp', '=', 'ats.id_trx_debitur')
            ->where('ats.status', 'Unpaid')
            ->whereIn('ats.id_trx_debitur', $debiturIds)
            ->groupBy(
                'apg.payment_id',
                'apg.order_id',
                'apg.order_payment_token',
                'apg.order_payment_url',
                'ats.tenor_sequence',
                'ajp_invoice_header.trx_no',
                'ajp_invoice_header.total_amount'
            )
            ->get();
    }

    public function getTenorDataForManualUpload(string $trxNo, array $invoiceNumbers)
    {
        return AjpDebiturTenorSchedule::query()
            ->from('multiguna_trx_ajp as ajt')
            ->join('trx_debitur_ajp as td', 'td.id_multiguna_ajp', '=', 'ajt.id_multiguna_ajp')
            ->join('ajp_tenor_schedule as ats', 'ats.id_trx_debitur', '=', 'td.id_trx_debitur_ajp')
            ->select([
                'ajt.id_multiguna_ajp',
                'ats.schedule_id',
                'ats.id_trx_debitur',
                'ats.tenor_sequence',
                'ats.invoice_number',
                'ats.amount',
                'td.id_trx_debitur_ajp',
                'td.no_sp_detail',
            ])
            ->whereIn('ats.invoice_number', $invoiceNumbers)
            ->where('ajt.trx_no', $trxNo)
            ->get();
    }

    public function getAjpHeaderByTrxNo(string $trxNo)
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)
            ->select('no_surat_permohonan')
            ->first();
    }

    public function createAjpInvoiceHeader(array $payload): AjpDebiturInvoiceHeader
    {
        return AjpDebiturInvoiceHeader::create($payload);
    }

    public function createAjpPaymentGateway(array $payload): AjpDebiturPaymentGateway
    {
        return AjpDebiturPaymentGateway::create($payload);
    }

    public function updateSchedulesToPaid(array $scheduleIds, int $invoiceId): void
    {
        if (empty($scheduleIds)) {
            return;
        }

        AjpDebiturTenorSchedule::whereIn('schedule_id', $scheduleIds)
            ->update([
                'invoice_id' => $invoiceId,
                'status' => 'Paid',
            ]);
    }

    public function createLampiranPembayaran(array $payload): PenjaminanLampiranDtl
    {
        return PenjaminanLampiranDtl::create($payload);
    }
}
