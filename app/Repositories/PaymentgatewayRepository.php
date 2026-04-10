<?php

namespace App\Repositories;

use App\Models\PenjaminanTransaction;

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
            ->first(); // ✅ object
    }
}
