<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrxCstbInvoiceHeader extends Model
{
    use HasFactory;

    protected $table = 'trx_cstb_invoice_header';
    protected $primaryKey = 'cstb_invoice_id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'cstb_schedule_id',
        'invoice_scope',
        'total_amount',
        'status',
        'is_manual',
        'tenor_sequence',
        'created_at',
        'updated_at'
    ];

    public function suretybondTenorSchedule()
    {
        return $this->belongsTo(CustomBondTenorSchedule::class, 'cstb_schedule_id', 'cstb_schedule_id');
    }

    public function TrxCstbPaymentGateway()
    {
        return $this->hasMany(TrxCstbPaymentGateway::class, 'cstb_invoice_id', 'cstb_invoice_id');
    }
}
