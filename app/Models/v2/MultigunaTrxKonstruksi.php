<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Model;

class MultigunaTrxKonstruksi extends Model
{
    protected $table = 'multiguna_trx_kreditkonstruksi';
    protected $primaryKey = 'id_multiguna_konstruksi';
    public $timestamps = true;

    protected $fillable = [
        'id_multiguna_konstruksi',
        'trx_no',
        'jenis_product',
        'jenis_product_description',
        'pks_number',
        'fee_base_number',
        'fee_base_percentage',
        'text_certified',
        'bank_name',
        'bank_code',
        'created_at',
        'updated_at',
    ];
}
