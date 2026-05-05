<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KontraBankGaransiPaymentGateway extends Model
{
    use HasFactory;

    protected $table = 'kbg_payment_gateway';
    protected $primaryKey = 'kbg_payment_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'kbg_payment_id',
        'status',
        'kbg_invoice_id',
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
        return $this->belongsTo(KontraBankGaransiInvoiceHeader::class, 'kbg_invoice_id', 'kbg_invoice_id');
    }
}
