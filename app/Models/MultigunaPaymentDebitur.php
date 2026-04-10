<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MultigunaPaymentDebitur extends Model
{
    use HasFactory;
    protected $table = 'multiguna_payment_debitur';
    protected $primaryKey = 'id_trx_debitur_payment';
    public $incrementing = true;
    protected $keyType = 'int';


    protected $fillable = [
        'id_trx_debitur',
        'invoice_number',
        'tenor_sequence',
        'due_date',
        'status',
        'payment_amount_ijp',
        'amount_paid',
        'transaction_time',
        'settlement_time',
        'expiry_date_time',
        'order_id',
        'order_payment_url',
        'order_payment_token'
    ];

    public function transactionMultigunaPayment()
    {
        return $this->belongsTo(MultigunaDebitur::class, 'id_trx_debitur', 'id_trx_debitur');
    }
}
