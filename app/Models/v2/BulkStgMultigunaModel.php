<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Model;

class BulkStgMultigunaModel extends Model
{
    protected $table = 'bulk_stg_penjaminan_multiguna';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'mitra_id',
        'bulk_no',
        'nomor_surat_permohonan',
        'jenis_produk',
        'nomor_pks',
        'bank',
        'bank_cabang',
        'fee_base',
        'teks_penjaminan',
        'tgl_surat_pengajuan',
        'is_split',
        'is_valid',
        'is_processed',
        'status',
        'invalid_msg',
        'attachments',
        'debitur',
        'created_by_id',
        'created_by_name',
        'created_at',
        'updated_at',
    ];
}
