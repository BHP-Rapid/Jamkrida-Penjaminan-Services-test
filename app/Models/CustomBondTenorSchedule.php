<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomBondTenorSchedule extends Model
{
    use HasFactory;

    protected $table = 'custombond_tenor_schedule';
    protected $primaryKey = 'cstb_schedule_id';
    public $incrementing = true;
    protected $keyType = 'int';
    protected $fillable = [
        'id_bond',
        'tenor_sequence',
        'due_date',
        'invoice_number',
        'amount',
        'status',
        'updated_at',
        'created_at',
        'kwitansi',
    ];

    public function transaction()
    {
        return $this->belongsTo(CustomBondTransaction::class, 'id_bond', 'id_bond');
    }

    public function trxSrtbInvoiceHeaders()
    {
        return $this->hasMany(TrxCstbInvoiceHeader::class, 'cstb_schedule_id', 'cstb_schedule_id');
    }
}
