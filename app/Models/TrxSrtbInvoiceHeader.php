<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrxSrtbInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'trx_srtb_invoice_header';
    protected $primaryKey = 'srtb_invoice_id';

    // Define fillable attributes for mass assignment
    protected $fillable = [
        'srtb_schedule_id',
        'invoice_scope',
        'total_amount',
        'status',
        'is_manual',
        'created_at',
        'updated_at'
    ];
    public function suretybondTenorSchedule()
    {
        return $this->belongsTo(SuretybondTenorSchedule::class, 'srtb_schedule_id', 'srtb_schedule_id');
    }

        public function TrxSrtbPaymentGateway()
    {
        return $this->hasMany(TrxSrtbPaymentGateway::class, 'srtb_schedule_id', 'srtb_schedule_id');
    }
}
