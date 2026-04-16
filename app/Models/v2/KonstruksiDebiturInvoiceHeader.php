<?php

namespace App\Models\v2;

use App\Models\PenjaminanTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KonstruksiDebiturInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'konstruksi_debitur_invoice_header';
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

    public function tenorScheduleKonstruksi(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturKonstruksi::class, 'invoice_id', 'invoice_id');
    }

    public function paymentsKonstruksi(): HasMany
    {
        return $this->hasMany(KonstruksiDebiturPaymentGateway::class, 'invoice_id', 'invoice_id');
    }
}
