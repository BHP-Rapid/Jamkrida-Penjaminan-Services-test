<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuretyBondTransaction extends Model
{
    use HasFactory;

    protected $table = 'surety_bond_transaction';
    protected $foreignKey = 'trx_no';
    protected $primaryKey = 'id_trx_product';

    protected $fillable = [
        'id_trx_product',
        'trx_no',
        'jenis_bond',
        'jenis_bond_description',
        'jenis_persyaratan',
        'skema_penalty',
        'sektor',
        'principal_name',
        'obligee_name',
        'sp_polis',
        'is_deposit',
        'id_institution',
        'is_bast',
        'no_surat_bast',
        'bast_date',
        'project_name',
        'project_amount',
        'amount_bond',
        'bond_percentage',
        'start_period_date',
        'end_period_date',
        'jenis_surat_perjanjian',
        'tgl_surat_perjanjian',
        'no_surat_perjanjian',
        'total_day',
        'province',
        'tarif_percentage',
        'ijp_amount',
        'agunan_amount',
        'stamp_amount',
    ];

    public function penjaminanHeader()
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no')
            ->withDefault(['trx_no' => null]);
    }
}
