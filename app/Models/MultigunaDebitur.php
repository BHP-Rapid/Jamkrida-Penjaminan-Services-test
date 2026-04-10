<?php

namespace App\Models;

use App\Helper\AesHelper as HelperAesHelper;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MultigunaDebitur extends Model
{
    use HasFactory;
    protected $table = 'multiguna_debitur';
    protected $primaryKey = 'id_trx_debitur';

    protected $fillable = [
        'multiguna_trx_id',
        'institution_id',
        'no_sp_detail',
        'ijk',
        'debitur_name',
        'debitur_address',
        'tgl_lahir',
        'jenis_agunan',
        'jenis_makful_anhu',
        'jw_bulan',
        'marginbagi_hasilujrah_thn',
        'no_sp_core_debitur',
        'is_active',
        'nik',
        'nilai_agunan',
        'nilai_kafalah',
        'plafond_pembiayaan',
        'plafond_max_debitur',
        'tanggal_realisasi',
        'tanggal_jatuh_tempo',
        'tenaga_kerja',
        'penggunaan_pembiayaan',
        'jenis_penjaminan',
        'status_debitur'
    ];

    public function multigunaTransaction()
    {
        return $this->belongsTo(MultigunaTransaction::class, 'multiguna_trx_id', 'id_multiguna');
    }


    public function paymentDebitur()
    {
        return $this->hasMany(MultigunaPaymentDebitur::class, 'id_trx_debitur', 'id_trx_debitur');
    }

    public function invoiceItemFullPayment()
    {
        return $this->hasMany(MultigunaInvoiceFullPayment::class, 'id_trx_debitur', 'id_trx_debitur');
    }

    public function tenorSchedulePayment()
    {
        return $this->hasMany(MultigunaTenorSchedule::class, 'id_trx_debitur', 'id_trx_debitur');
    }

    protected function nik(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn($value) => $value ? HelperAesHelper::decrypt($value, $key) : null,
            set: fn($value) => $value ? HelperAesHelper::encrypt($value, $key) : null,
        );
    }

    protected function npwp(): Attribute
    {
        $key = base64_decode(config('services.secure.key'));

        return Attribute::make(
            get: fn($value) => $value ? HelperAesHelper::decrypt($value, $key) : null,
            set: fn($value) => $value ? HelperAesHelper::encrypt($value, $key) : null,
        );
    }
}
