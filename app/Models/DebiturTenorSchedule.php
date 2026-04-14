<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebiturTenorSchedule extends Model
{
    use HasFactory;
    protected $table = 'debitur_tenor_schedule';
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
    ];

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturDefaultBase::class, 'id_trx_debitur', 'id_trx_debitur');
    }
}
