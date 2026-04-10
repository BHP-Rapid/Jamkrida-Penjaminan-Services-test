<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrxInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'transaction_invoice_header';
    protected $primaryKey = 'invoice_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'trx_no',
        'multiguna_trx_id',
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

    public function multigunaTransaction(): BelongsTo
    {
        return $this->belongsTo(MultigunaTransaction::class, 'multiguna_trx_id', 'id_multiguna');
    }

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(MultigunaDebitur::class, 'id_trx_debitur', 'id_trx_debitur');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TrxPaymentGateway::class, 'invoice_id', 'invoice_id');
    }


    public function invoiceItemFullPayment()
    {
        return $this->hasMany(MultigunaInvoiceFullPayment::class, 'invoice_id', 'invoice_id');
    }

    // public function SuretyBondPayment(): HasMany
    // {
    //     return $this->hasMany(SuretyBondTenorSchedule::class, 'invoice_id', 'invoice_id');
    // }
}
