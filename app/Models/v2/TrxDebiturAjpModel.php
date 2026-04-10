<?php

namespace App\Models\v2;

use App\Models\AjpDebiturInvoiceHeader;
use App\Models\AjpDebiturTenorSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrxDebiturAjpModel extends Model
{
    protected $table = 'trx_debitur_ajp';
    protected $primaryKey = 'id_trx_debitur_ajp';

    public $timestamps = true;

    protected $fillable = [
        'id_multiguna_ajp',
        'no_urut',
        'nama_nasabah',
        'alamat_nasabah',
        'no_invoice',
        'tanggal_invoice',
        'tanggal_jatuh_tempo_invoice',
        'nilai_invoice',
        'nama_payor',
        'jenis_payor',
        'no_perjanjian_pembayaran',
        'tanggal_perjanjian_pembayaran',
        'penggunaan_kredit',
        'plafond_kredit',
        'nilai_penjaminan',
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
        'jenis_penjaminan',
        'status_debitur',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'nilai_invoice' => 'decimal:2',
        'plafond_kredit' => 'decimal:2',
        'nilai_penjaminan' => 'decimal:2',
        'nilai_agunan' => 'decimal:2',
        'ijp' => 'decimal:2',
        'tanggal_invoice' => 'date',
        'tanggal_jatuh_tempo_invoice' => 'date',
        'tanggal_perjanjian_pembayaran' => 'date',
        'tanggal_realisasi' => 'date',
        'tanggal_jatuh_tempo' => 'date',
    ];

    public function multigunaAjp(): BelongsTo
    {
        return $this->belongsTo(
            MultigunaTrxAjpModel::class,
            'id_multiguna_ajp',
            'id_multiguna_ajp'
        );
    }

    public function tenorSchedules(): HasMany
    {
        return $this->hasMany(
            AjpDebiturTenorSchedule::class,
            'id_trx_debitur',
            'id_trx_debitur_ajp'
        );
    }

    public function invoiceHeaders(): HasMany
    {
        return $this->hasMany(
            AjpDebiturInvoiceHeader::class,
            'id_trx_debitur',
            'id_trx_debitur_ajp'
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
