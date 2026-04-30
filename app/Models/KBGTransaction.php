<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KBGTransaction extends Model
{
    use HasFactory;
    protected $table = 'kbg_transaction';
    protected $primaryKey = 'id_trx_product';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'trx_no',
        'sp_polis',
        'jenis_garansi',
        'jenis_garansi_description',
        'jenis_persyaratan',
        'skema_penalty',
        'jenis_surat_perjanjian',
        'no_surat_perjanjian',
        'tgl_surat_perjanjian',
        'bank_code',
        'bank_name',
        'sektor',
        'id_institution',
        'principal_name',
        'obligee_name',
        'is_bast',
        'no_surat_bast',
        'bast_date',
        'project_name',
        'project_amount',
        'amount_garansi',
        'garansi_percentage',
        'start_period_date',
        'end_period_date',
        'total_day',
        'province',
        'tarif_percentage',
        'ijp_amount',
        'agunan_amount',
        'stamp_amount',
        'created_at',
        'updated_at'
    ];

    public function transactionPenjaminanHeader()
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no');
    }
}
