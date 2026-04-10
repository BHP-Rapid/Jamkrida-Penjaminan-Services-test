<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultigunaTenorSchedule extends Model
{
    use HasFactory;
    protected $table = 'multiguna_tenor_schedule';
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
        'invoice_number_collateral',
        'collateral_amount',
    ];

    public function debitur(): BelongsTo
    {
        return $this->belongsTo(MultigunaDebitur::class, 'id_trx_debitur', 'id_trx_debitur');
    }
}
