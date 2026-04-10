<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class TrxDebiturKprModel extends Model
{
    protected $table = 'trx_debitur_kpr';
    protected $primaryKey = 'id_trx_debitur_kpr';
    public $timestamps = true;

    protected $fillable = [
        'id_multiguna_kpr',
        'nama_nasabah',
        'alamat_nasabah',
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
        'catatan',
        'no_sp_detail',
        'no_sp_core_debitur',
        'institution_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'plafond_kredit' => 'decimal:2',
        'nilai_penjaminan' => 'decimal:2',
        'nilai_agunan' => 'decimal:2',
        'ijp' => 'decimal:2',
        'suku_bunga' => 'decimal:2',

        'tanggal_usia' => 'date',
        'tanggal_realisasi' => 'date',
        'tanggal_jatuh_tempo' => 'date',
    ];

    public function multigunaKpr(): BelongsTo
    {
        return $this->belongsTo(
            MultigunaTrxKprModel::class,
            'id_multiguna_kpr',
            'id_multiguna_kpr'
        );
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}