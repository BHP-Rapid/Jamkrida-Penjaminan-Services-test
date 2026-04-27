<?php

namespace App\Repositories;

use App\Models\KURTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use Illuminate\Support\Facades\DB;

class KURRepository
{
    public function getLastTrxNo($year, $month)
    {
        return PenjaminanTransaction::lockForUpdate()
            // ->where('trx_no', 'like', "PNJ-$year-$month%")
            ->where('trx_no', 'like', 'PNJ-' . $year . '-' . $month . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function getLastLoanNumber($mitra_id, $year)
    {
        $prefix = $mitra_id . $year;
        return TrxDebiturDefaultBase::lockForUpdate()
            ->where('loan_number', 'like', $prefix . '%')
            ->orderBy('loan_number', 'desc')
            ->value('loan_number');
    }

    public function getTenantMitraData($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }

    public function getPenjaminanDetail($trx_no)
    {
        return PenjaminanTransaction::join('kur_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
    }

    public function getDebiturWithInstitution($id_kur)
    {
        return DB::table('institution as a')
        ->join('trx_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.kur_trx_id', $id_kur)
            ->select('b.*', 'a.*')
            ->get();
    }

    public function getLampiranKURDetail($trx_no)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();
    }

    public function getKURFlow($trx_no)
    {
        return PenjaminanFlow::where('trx_no', $trx_no)->orderBy('created_at', 'desc')->get();
    }

    public function insertHeaderKur($data)
    {
        return PenjaminanTransaction::create($data);
    }

    public function insertTrxKur($data)
    {
        return KURTransaction::create($data);
    }
}
