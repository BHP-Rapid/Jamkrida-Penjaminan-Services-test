<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrxSrtbPaymentGateway extends Model
{
    use HasFactory;

    protected $table = 'trx_srtb_payment_gateway';
    protected $primaryKey = 'srtb_payment_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'srtb_payment_id',
        'status',
        'srtb_invoice_id',
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

    public function SuretyBondInvoiceHeader()
    {
        return $this->belongsTo(TrxSrtbInvoiceHeader::class, 'srtb_invoice_id', 'srtb_invoice_id');
    }
}
