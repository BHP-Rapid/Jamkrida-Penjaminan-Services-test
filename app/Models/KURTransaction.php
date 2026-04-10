<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KURTransaction extends Model
{
    use HasFactory;
    protected $table = 'kur_transaction';
    protected $primaryKey = 'id_kur';
    public $incrementing = true;
    protected $keyType = 'int';

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

    public function transactionPenjaminanHeader()
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no');
    }
}
