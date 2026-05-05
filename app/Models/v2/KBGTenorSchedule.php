<?php

namespace App\Models\v2;

use App\Models\KBGTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KBGTenorSchedule extends Model
{
    use HasFactory;
    protected $table = 'kbg_tenor_schedule';
    protected $primaryKey = 'kbg_schedule_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_trx_product',
        'tenor_sequence',
        'due_date',
        'invoice_number',
        'amount',
        'status',
        'created_at',
        'updated_at'
    ];

    public function transactionKBG()
    {
        return $this->belongsTo(KBGTransaction::class, 'id_trx_product', 'id_trx_product');
    }
}
