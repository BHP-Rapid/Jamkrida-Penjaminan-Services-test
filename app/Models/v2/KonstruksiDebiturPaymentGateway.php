<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KonstruksiDebiturPaymentGateway extends Model
{
    use HasFactory;
    protected $table = 'konstruksi_debitur_payment_gateway';
    protected $primaryKey = 'payment_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'invoice_id',
        'status',
        'payment_amount_ijp',
        'transaction_time',
        'settlement_time',
        'expiry_date_time',
        'order_id',
        'order_payment_url',
        'order_payment_token',
        'created_at',
        'updated_at'
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(KonstruksiDebiturInvoiceHeader::class, 'invoice_id', 'invoice_id');
    }
}
