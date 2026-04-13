<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuretyBondTenorSchedule extends Model
{
    use HasFactory;

    protected $table = 'suretybond_tenor_schedule';
    protected $primaryKey = 'srtb_schedule_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id_trx_product',
        'tenor_sequence',
        'due_date',
        'invoice_number',
        'invoice_number_collateral',
        'invoice_id',
        'amount',
        'collateral_amount',
        'status',
        'status_collateral'
    ];

    public function transaction()
    {
        return $this->belongsTo(SuretyBondTransaction::class, 'id_trx_product', 'id_trx_product');
    }

    public function trxSrtbInvoiceHeaders()
    {
        return $this->hasMany(TrxSrtbInvoiceHeader::class, 'srtb_schedule_id', 'srtb_schedule_id');
    }
}
