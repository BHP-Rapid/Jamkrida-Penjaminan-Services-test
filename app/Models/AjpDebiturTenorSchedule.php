<?php

namespace App\Models;

use App\Models\v2\TrxDebiturAjpModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AjpDebiturTenorSchedule extends Model
{
    use HasFactory;

    protected $table = 'ajp_tenor_schedule';
    protected $primaryKey = 'schedule_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_trx_debitur',
        'tenor_sequence',
        'due_date',
        'invoice_number',
        'amount',
        'status',
        'invoice_id',
    ];

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturAjpModel::class, 'id_trx_debitur', 'id_trx_debitur_ajp');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AjpDebiturInvoiceHeader::class, 'invoice_id', 'invoice_id');
    }
}
