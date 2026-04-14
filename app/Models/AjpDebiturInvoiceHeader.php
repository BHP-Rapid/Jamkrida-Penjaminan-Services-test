<?php

namespace App\Models;

use App\Models\v2\MultigunaTrxAjpModel;
use App\Models\v2\TrxDebiturAjpModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AjpDebiturInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'ajp_invoice_header';
    protected $primaryKey = 'invoice_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'trx_no',
        'debitur_trx_id',
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

    public function headerAjp(): BelongsTo
    {
        return $this->belongsTo(MultigunaTrxAjpModel::class, 'debitur_trx_id', 'id_multiguna_ajp');
    }

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturAjpModel::class, 'id_trx_debitur', 'id_trx_debitur_ajp');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AjpDebiturPaymentGateway::class, 'invoice_id', 'invoice_id');
    }
}
