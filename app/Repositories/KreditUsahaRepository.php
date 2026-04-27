<?php

namespace App\Repositories;

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
}
