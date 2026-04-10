<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultigunaTrxKreditMikroKecil extends Model
{
    use HasFactory;
    protected $table = 'multiguna_trx_kredit_mikro_kecil';
    protected $primaryKey = 'id_multiguna_kredit_mikro_kecil';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $fillable = [
        'trx_no',
        'jenis_product',
        'jenis_product_description',
        'pks_number',
        'fee_base_number',
        'fee_base_percentage',
        'text_certified',
        'bank_name',
        'bank_code',
    ];
    public function PenjaminanTransactionMikroKecil(): BelongsTo
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no');
    }
}
