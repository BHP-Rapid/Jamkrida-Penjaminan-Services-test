<?php

namespace App\Services\KURServices;

use Carbon\Carbon;

class KURGeneratePayload
{
    public static function generateHeaderKUR($request, $user, $trx_no, $mitra_alias)
    {
        $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
        $spSplit = $request->boolean('data.spSplit');
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        return [
            'trx_no' => $trx_no,
            'sp_split' => $spSplit,
            'no_surat_permohonan' => $request->data['noSuratPermohonan'],
            'tanggal_surat_permohonan' => $permohonanDate,
            'trx_status' => $request->data['trx_status'],
            'status_sync_creatio' => 0,
            'created_by_name' => $user->name,
            'created_at' => $time_now_jakarta,
            'created_by_id' => $user->user_id,
            'product' => 'kur',
            'mitra_id' => $mitra_alias,
            'no_rek' => '012312'
        ];
    }

    public static function generateTrxKUR($data, $trx_no)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        return [
            'trx_no' => $trx_no,
            'jenis_product_description' => 'Kredit Usaha Rakyat',
            'pks_number' => $data['pks'],
            'fee_base_number' => $data['feeBasePercentage'],
            'fee_base_percentage' => $data['feeBasePercentage'],
            'bank_name' => $data['bankCabang'],
            'bank_code' => $data['bank'],
            'text_certified' => $data['teksPenjaminanSp'],
            'created_at' => $time_now_jakarta
        ];
    }

    public static function generateUpdateDraftHeader($request, $user)
    {
        $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
        $spSplit = $request->boolean('data.spSplit');
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        return [
                'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->data['trx_status'],
                'status_sync_creatio' => 0,
                'sp_split' => $spSplit,
                'updated_at' => $time_now_jakarta,
                'updated_by_id' => $user->user_id,
                'updated_by_name' => $user->name,
        ];
    }

    public static function generateUpdateDraftTrx($data)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        return [
            'pks_number' => $data['pks'],
            'fee_base_number' => $data['feeBasePercentage'],
            'fee_base_percentage' => $data['feeBasePercentage'],
            'bank_name' => $data['bankCabang'],
            'bank_code' => $data['bank'],
            'text_certified' => $data['teksPenjaminanSp'],
            'updated_at' => $time_now_jakarta,
        ];
    }

    public static function generateInvoiceHeader($trx_no, $tenor_data, bool $is_manual)
    {
        $id_kur = $tenor_data->pluck('id_kur')[0];
        $sequence = $tenor_data->pluck('tenor_sequence')[0];
        $scope = count($tenor_data) > 1 ? 'Merge Payment' : ($sequence == 0 ? 'Full Payment' : 'Installment');
        $total = $tenor_data->sum('amount');
        return [
            'trx_no' => $trx_no,
            'debitur_trx_id' => $id_kur,
            'invoice_scope' => $scope,
            'total_amount' => $total,
            'status' => 'Paid',
            'is_manual' => $is_manual ? 1 : 0,
            'tenor_sequence' => $sequence
        ];
    }
}
