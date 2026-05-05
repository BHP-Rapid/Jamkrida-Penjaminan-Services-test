<?php

namespace App\Services\KBGServices;

use Carbon\Carbon;

class KBGGeneratePayload
{
    public static function generateHeaderKBG($trx_no, $mitra_alias, $status, $payload, $user)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        $fallback = function (string $key, $default = null) use ($payload) {
            if (array_key_exists($key, $payload) && $payload[$key] != null) {
                return $payload[$key];
            }
            return $default;
        };

        return [
            'trx_no' => $trx_no,
            'no_surat_permohonan' => $fallback('noSuratPermohonan', 'DRAFT-' . $trx_no),
            'sp_split' => $fallback('isSplit'),
            'trx_status' => $status,
            'status_sync_creatio' => 0,
            'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan', $time_now_jakarta),
            'created_by_name' => $user->name,
            'created_at' => $time_now_jakarta,
            'created_by_id' => $user->user_id,
            'no_rek' => '123',
            'mitra_id' => $mitra_alias,
            'product' => 'kbg'
        ];
    }

    public static function generateHeaderUpdateKBG(array $payload, object $user, bool $isSubmit = false)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        $fallback = function (string $key, $default = null) use ($payload) {
            if (array_key_exists($key, $payload) && $payload[$key] != null) {
                return $payload[$key];
            }
            return $default;
        };

        $result = [
            'no_surat_permohonan' => $fallback('noSuratPermohonan'),
            'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan'),
            'sp_split' => $fallback('isSplit'),
            'updated_by_id' => $user->user_id,
            'updated_by_name' => $user->name,
            'updated_at' => $time_now_jakarta
        ];

        if($isSubmit) {
            $result['trx_status'] = 'NA';
        }

        return $result;
    }
}
