<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenjaminanPayment extends Model
{
    use HasFactory;

    protected $table = 'penjaminan_payment';
    protected $fillable = [
        'id',
        'trx_no',
        'invoice_number',
        'status',
        'payment_type',
        'bank_type',
        'amount',
        'no_surat_permohonan',
        'tenor_sequence',
        'transaction_time',
        'settlement_time',
        'expiry_date_time',
        'due_date',
        'created_by_id',
        'created_by_name',
        'updated_by_id',
        'updated_by_name',
        'order_id',
        'order_payment_token',
        'order_payment_url'
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_time' => 'datetime',
        'settlement_time'  => 'datetime',
        'expiry_date_time' => 'datetime',
    ];

    public function penjaminanHdr()
    {
        return $this->belongsTo(PenjaminanHdr::class, 'trx_no', 'trx_no');
    }
}
