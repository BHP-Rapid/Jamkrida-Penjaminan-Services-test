<?php

namespace App\Models;

use App\Models\v2\KreditUsahaTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrxDebiturDefaultBase extends Model
{
    use HasFactory;
    protected $table = 'trx_debitur';
    // protected $foreignKey = 'id_kur';
    // protected $foreignKey = 'id_multiguna_kredit_mikro_kecil';
    protected $primaryKey = 'id_trx_debitur';

    protected $fillable = [
        'kur_trx_id',
        'kredit_usaha_trx_id',
        'kredit_mikro_trx_id',
        'nama_nasabah',
        'alamat_nasabah',
        'jenis_penjaminan',
        'penggunaan_kredit',
        'plafond_kredit',
        'nilai_penjaminan',
        'tanggal_usia',
        'instansi',
        'suku_bunga',
        'jangka_waktu',
        'tanggal_realisasi',
        'tanggal_jatuh_tempo',
        'jenis_agunan',
        'nilai_agunan',
        'tenaga_kerja',
        'jenis_terjamin',
        'ijp',
        'loan_number',
        'status_debitur',
        'base_plafond',
        'jenis_kredit',
        'sp3',
        'limit_penarikan',
        'npwp_principal',
        'no_sp_detail',
        'no_sp_core_debitur',
        'institution_id',
    ];

    public function kreditMikro()
    {
        return $this->belongsTo(MultigunaTrxKreditMikroKecil::class, 'kredit_mikro_trx_id', 'id_kredit_usaha_transaction');
    }

    public function kur()
    {
        return $this->belongsTo(KURTransaction::class, 'kur_trx_id', 'id_kur');
    }

    public function kreditUsaha()
    {
        return $this->belongsTo(KreditUsahaTransaction::class, 'kredit_usaha_trx_id', 'id_multiguna_kredit_mikro_kecil');
    }

    public function owner()
    {
        return $this->kreditMikro
            ?? $this->kur
            ?? $this->kreditUsaha;
    }
}
