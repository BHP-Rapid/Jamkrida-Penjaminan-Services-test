<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Model;

class TrxDebiturKonstruksi extends Model
{
    protected $table = 'trx_debitur_construction';
    protected $primaryKey = 'id_trx_debitur_konstruksi';
    public $timestamps = true;

    protected $fillable = [
        'id_trx_debitur_konstruksi',
        'id_multiguna_konstruksi',
        'nilai_penjaminan',
        'jangka_waktu',
        'tanggal_realisasi',
        'tanggal_jatuh_tempo',
        'suku_bunga',
        'tanggal_kontrak',
        'nama_proyek',
        'nilai_proyek',
        'nilai_kredit_per_proyek',
        'dana_diendapkan',
        'jangka_waktu_proyek',
        'nomor_memo',
        'tanggal_memo',
        'tenaga_kerja',
        'ijp',
        'loan_number',
        'no_sp_detail',
        'no_sp_core_debitur',
        'institution_id',
        'created_at',
        'updated_at',
    ];
}
