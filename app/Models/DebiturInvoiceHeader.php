<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebiturInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'debitur_invoice_header';
    protected $primaryKey = 'invoice_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'trx_no',
        'debitur_trx_id',
        // 'invoice_number',
        'invoice_scope',
        'id_trx_debitur',
        'total_amount',
        'status',
        'created_at',
        'updated_at',
        'tenor_sequence',
        'is_manual',
    ];

    public function transactionHeader(): BelongsTo
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no');
    }

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturDefaultBase::class, 'id_trx_debitur', 'id_trx_debitur');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TrxPaymentGateway::class, 'invoice_id', 'invoice_id');
    }

}
