<?php

namespace App\Repositories;

use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use Illuminate\Support\Facades\DB;

class MultigunaRepository
{
    public function getMultigunaDetail(string $trxNo)
    {
        $query = PenjaminanTransaction::join('multiguna_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
 ;

        return $query;
    }

    public function getMultigunaDebitur(int $multigunaId)
    {
     $query = DB::table('institution as a')
            ->join('multiguna_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.multiguna_trx_id', $multigunaId)
            ->select('b.*', 'a.*')
            ->get();
        return $query;

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
}
