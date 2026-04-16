<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KonstruksiDebiturTenorSchedule extends Model
{
    use HasFactory;
    protected $table = 'konstruksi_debitur_tenor_schedule';
    protected $primaryKey = 'schedule_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_trx_debitur',
        'tenor_sequence',
        'due_date',
        'invoice_number',
        'invoice_id',
        'amount',
        'status',
    ];

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(TrxDebiturKonstruksi::class, 'id_trx_debitur', 'id_trx_debitur_konstruksi');
    }
}
