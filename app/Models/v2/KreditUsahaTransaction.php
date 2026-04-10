<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Helpers\AesHelper;
use Illuminate\Support\Facades\Log;

class KreditUsahaTransaction extends Model
{
    protected $table = 'kredit_usaha_transaction';

    /**
     * Karena tabel tidak memiliki kolom `id`
     */
    protected $primaryKey = 'id_kredit_usaha_transaction';
    public $incrementing =   true;

    /**
     * Kolom yang boleh di-mass assign
     */
    protected $fillable = [

        'id_kredit_usaha_transaction',
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
