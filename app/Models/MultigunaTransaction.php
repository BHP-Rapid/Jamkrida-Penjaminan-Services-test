<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultigunaTransaction extends Model
{
    use HasFactory;
    protected $table = 'multiguna_transaction';
    protected $primaryKey = 'id_multiguna';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;
    protected $fillable = [
        'trx_no',
        'pks_number',
        'jenis_product',
        'jenis_product_description',
        'fee_base_number',
        'fee_base_percentage',
        'bank_name',
        'bank_code',
        'text_certified'
    ];

    // public function multigunaTransaction(): BelongsTo
    // {
    //     return $this->belongsTo(MultigunaTransaction::class, 'multiguna_trx_id', 'id_multiguna');
    // }
}
