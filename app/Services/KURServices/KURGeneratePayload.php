<?php

namespace App\Services\KURServices;

use Carbon\Carbon;

class KURGeneratePayload
{
    public static function generateHeaderKUR($request, $user, $trx_no, $now_time, $mitra_alias)
    {
        $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
        $spSplit = $request->boolean('data.spSplit');
        return [
            'trx_no' => $trx_no,
            'sp_split' => $spSplit,
            'no_surat_permohonan' => $request->data['noSuratPermohonan'],
            'tanggal_surat_permohonan' => $permohonanDate,
            'trx_status' => $request->data['trx_status'],
            'status_sync_creatio' => 0,
            'created_by_name' => $user->name,
            'created_at' => $now_time,
            'created_by_id' => $user->user_id,
            'product' => 'kur',
            'mitra_id' => $mitra_alias,
            'no_rek' => '012312'
        ];
    }

    public static function generateTrxKUR($data, $trx_no, $now_time)
    {
        return [
            'trx_no' => $trx_no,
            'jenis_product_description' => 'Kredit Usaha Rakyat',
            'pks_number' => $data['pks'],
            'fee_base_number' => $data['feeBasePercentage'],
            'fee_base_percentage' => $data['feeBasePercentage'],
            'bank_name' => $data['bankCabang'],
            'bank_code' => $data['bank'],
            'text_certified' => $data['teksPenjaminanSp'],
            'created_at' => $now_time
        ];
    }
}
