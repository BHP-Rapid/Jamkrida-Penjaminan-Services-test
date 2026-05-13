<?php

namespace App\Services\SuretyBondServices;

class SrtbGeneratePayload
{
    public static function makeSrtbHeaderPayload(string $trx_no, array $payload, string $mitra_alias, string $status, object $user)
    {
        $fallback = function ($key, $default = null) use ($payload) {
            return $payload[$key] ?? $default;
        };
        $time_now_jakarta = now('Asia/Jakarta');
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
            'product' => 'srtb'
        ];
    }

    public static function makeSrtbTrxPayload(array $payload, string $mode = 'edit', $trx_no = null, $id_institution = null)
    {
        $fallback = function ($key, $default = null) use ($payload) {
            return $payload[$key] ?? $default;
        };
        $bastCheck = $fallback('isBast') === 1;

        $result = [
            'jenis_bond' => $fallback('jenisBond'),
            'jenis_bond_description' => $fallback('jenisBondDescription'),
            'jenis_persyaratan' => $fallback('jenisPernyataan'),
            'skema_penalty' => $fallback('skemaPenalty'),
            'sektor' => $fallback('sektor'),
            'principal_name' => $fallback('namaPrincipal'),
            'obligee_name' => $fallback('namaObligee'),
            'is_bast' => $fallback('isBast'),
            'no_surat_bast' => $bastCheck
                ? $fallback('noSuratBast')
                : null,
            'bast_date' => $bastCheck
                ? $fallback('tglSuratBast')
                : null,
            'project_name' => $fallback('namaProyek'),
            'project_amount' => $fallback('nilaiProyek'),
            'bond_percentage' => $fallback('nilaiBondPersentase'),
            'amount_bond' => $fallback('nilaiBond'),
            'start_period_date' => $fallback('periodeAwalBerlaku'),
            'end_period_date' => $fallback('periodeAkhirBerlaku'),
            'total_day' => $fallback('jangkaWaktu'),
            'province' => $fallback('propinsi'),
            'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
            'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
            'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
            'agunan_amount' => $fallback('nilaiAgunan'),
        ];

        switch($mode) {
            case 'create':
                $result['trx_no'] = $trx_no;
                $result['id_institution'] = $id_institution;
                break;
            default:
                break;
        }
        return $result;
    }
}
