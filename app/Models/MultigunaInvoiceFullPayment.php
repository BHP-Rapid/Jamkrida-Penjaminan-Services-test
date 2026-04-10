<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultigunaInvoiceFullPayment extends Model
{
    use HasFactory;
    protected $table = 'multiguna_invoice_fullpayment';
    protected $primaryKey = 'invoice_item_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'invoice_id',
        'id_trx_debitur',
        'amount',
        'created_at',
        'updated_at'
    ];

    public function invoiceHeader(): BelongsTo
    {
        return $this->belongsTo(TrxInvoiceHeader::class, 'invoice_id', 'invoice_id');
    }

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(MultigunaDebitur::class, 'id_trx_debitur', 'id_trx_debitur');
    }
}
