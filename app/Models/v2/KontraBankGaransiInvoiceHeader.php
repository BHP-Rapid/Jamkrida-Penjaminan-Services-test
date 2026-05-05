<?php

namespace App\Models\v2;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KontraBankGaransiInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'kbg_invoice_header';
    protected $primaryKey = 'kbg_invoice_id';

    // Define fillable attributes for mass assignment
    protected $fillable = [
        'kbg_schedule_id',
        'invoice_scope',
        'total_amount',
        'status',
        'is_manual',
        'created_at',
        'updated_at'
    ];
    public function suretybondTenorSchedule()
    {
        return $this->belongsTo(KBGTenorSchedule::class, 'kbg_schedule_id', 'kbg_schedule_id');
    }

        public function TrxSrtbPaymentGateway()
    {
        return $this->hasMany(KontraBankGaransiPaymentGateway::class, 'kbg_schedule_id', 'kbg_schedule_id');
    }
}
