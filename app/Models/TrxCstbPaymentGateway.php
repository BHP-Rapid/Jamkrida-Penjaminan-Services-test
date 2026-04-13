<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrxCstbPaymentGateway extends Model
{
    use HasFactory;

    protected $table = 'trx_cstb_payment_gateway';
    protected $primaryKey = 'cstb_payment_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'cstb_payment_id',
        'status',
        'cstb_invoice_id',
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
        return $this->belongsTo(TrxCstbInvoiceHeader::class, 'cstb_invoice_id', 'cstb_invoice_id');
    }
}
