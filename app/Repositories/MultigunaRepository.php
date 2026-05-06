<?php

namespace App\Repositories;

use App\Models\Institution;
use App\Models\MultigunaDebitur;
use App\Models\MultigunaTenorSchedule;
use App\Models\MultigunaTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxInvoiceHeader;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class MultigunaRepository
{
    public function getMultigunaDetail(string $trxNo)
    {
        $query = PenjaminanTransaction::join('multiguna_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();

        return $query;
    }

    public function getMultigunaDebitur(int $multigunaId)
    {
        return Institution::query()
            ->from('institution as a')
            ->join('multiguna_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.multiguna_trx_id', $multigunaId)
            ->select('b.*', 'a.*')
            ->get();
    }

    public function getMultigunaLampiran(string $trxNo)
    {
        $query = PenjaminanLampiranDtl::where('trx_no', $trxNo)->get();
        return $query;
    }

    public function getMultigunaFlow(string $trxNo)
    {
        $query = PenjaminanFlow::where('trx_no', $trxNo)->orderBy('created_at', 'desc')->get();
        return $query;
    }

    public function findPenjaminanForUpdate(string $trxNo)
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', $trxNo)
            ->firstOrFail();
    }

    public function updatePenjaminanDraft(PenjaminanTransaction $penjaminan, array $payload): void
    {
        $penjaminan->update($payload);
    }

    public function findMultigunaForUpdate(string $trxNo)
    {
        $row = DB::table('multiguna_transaction')
            ->where('trx_no', $trxNo)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            throw new Exception('Data multiguna tidak ditemukan');
        }

        return $row;
    }

    public function updateMultigunaDraft(string $trxNo, array $payload): void
    {
        DB::table('multiguna_transaction')
            ->where('trx_no', $trxNo)
            ->update($payload);
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

    public function createMultigunaTransaction(array $payload): MultigunaTransaction
    {
        return MultigunaTransaction::create($payload);
    }

    public function findMitraAlias(?string $mitraId): ?string
    {
        if (empty($mitraId)) {
            return null;
        }

        return TenantMitra::where('mitra_id', $mitraId)->value('alias');
    }

    public function getLatestLoanNumberByPrefix(string $prefix): ?string
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

    public function insertMultigunaDebitur(array $rows): void
    {
        if (!empty($rows)) {
            MultigunaDebitur::insert($rows);
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

    public function getNowJakarta(): Carbon
    {
        return Carbon::now('Asia/Jakarta');
    }

    public function getDetailHeaderPayment(string $trx_no, string $no_surat_permohonan, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
            ->join('multiguna_debitur as md', 'mt.id_multiguna', '=', 'md.multiguna_trx_id')
            ->join('multiguna_tenor_schedule as mts', 'md.id_trx_debitur', '=', 'mts.id_trx_debitur')
            ->where('tph.trx_no', $trx_no)
            ->where('mts.status', 'Pending')
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'mt.id_multiguna',
                'md.id_trx_debitur',
                'md.plafond_pembiayaan',
                'md.nik',
                'md.debitur_name',
                'mts.amount',
                'mts.invoice_number',
                'mts.due_date',
                'mts.status'
            ])
            ->get();
    }

    public function getDetailUnpaidPaymentMLT(string $trx_no)
    {
        return TrxInvoiceHeader::query()
            ->from('transaction_invoice_header as mih')
            ->join('multiguna_tenor_schedule as mts', 'mih.invoice_id', '=', 'mts.invoice_id')
            ->join('transaction_payment_gateway as mpg', 'mpg.invoice_id', '=', 'mih.invoice_id')
            ->join('multiguna_debitur as md', 'md.id_trx_debitur', '=', 'mts.id_trx_debitur')
            ->where('mih.trx_no', $trx_no)
            ->where('mih.status', 'Unpaid')
            ->select(
                'mpg.payment_id',
                'mih.invoice_id',
                'mpg.order_id',
                'mpg.order_payment_url',
                'mpg.order_payment_token',
                'mts.tenor_sequence',
                'mih.trx_no',
                'mih.total_amount',
                DB::raw('COUNT(md.id_trx_debitur) AS total_debitur')
            )
            ->groupBy(
                'mpg.payment_id',
                'mpg.order_id',
                'mpg.order_payment_url',
                'mts.tenor_sequence',
                'mih.trx_no',
                'mih.total_amount'
            )->get();
    }

    public function getDetailListHeader(string $trx_no, string $no_surat_permohonan, ?int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
            ->where('tph.trx_no', $trx_no)
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->where('tph.sp_split', $isSplit)
            ->select([
                'tph.*',
                'mt.id_multiguna',
            ])
            ->first();
    }

    public function getDetailListPaymentDebitur(int $id_multiguna)
    {
        return  MultigunaDebitur::where('multiguna_trx_id', $id_multiguna)
            ->select(
                'id_trx_debitur',
                'no_sp_detail',
                'loan_number',
                'nik',
                'tanggal_realisasi',
                'debitur_name'
            )
            ->orderBy('id_trx_debitur', 'asc')
            ->get();
    }

    public function getSchedules(array $debiturIds)
    {
        return MultigunaTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
            ->WhereIn('status', ['Unpaid', 'Pending'])
            ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
            ->orderBy('tenor_sequence', 'asc')
            ->get();
    }

    public function getUnpaidSchedules(array $debiturIds)
    {
        return TrxInvoiceHeader::select(
            'mpg.payment_id',
            'mpg.order_id',
            'mpg.order_payment_url',
            'mpg.order_payment_token',
            'mts.tenor_sequence',
            'transaction_invoice_header.trx_no',
            'transaction_invoice_header.total_amount',
            DB::raw('COUNT(md.id_trx_debitur) as total_debitur')
        )
            ->join('multiguna_tenor_schedule as mts', 'transaction_invoice_header.invoice_id', '=', 'mts.invoice_id')
            ->join('transaction_payment_gateway as mpg', 'mpg.invoice_id', '=', 'transaction_invoice_header.invoice_id')
            ->join('multiguna_debitur as md', 'md.id_trx_debitur', '=', 'mts.id_trx_debitur')
            // ->where('transaction_invoice_header.invoice_scope', '=', 'Merge Payment')
            ->where('mts.status', 'Unpaid')
            ->whereIn('mts.id_trx_debitur', $debiturIds)
            ->groupBy(
                'mpg.order_id',
                'mpg.order_payment_token',
                'mpg.order_payment_url',
                'mts.tenor_sequence',
                'transaction_invoice_header.trx_no',
                'transaction_invoice_header.total_amount'
            )
            ->get();
    }
}
