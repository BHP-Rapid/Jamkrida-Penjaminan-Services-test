<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomBondTransaction extends Model
{
    use HasFactory;

    protected $table = 'custom_bond_transaction';
    protected $foreignKey = 'trx_no';
    protected $primaryKey = 'id_bond';

    protected $fillable = [
        'id_bond',
        'trx_no',
        'jenis_bond',
        'jenis_bond_description',
        'jenis_persyaratan',
        'skema_penalty',
        'sp_polis',
        'sektor',
        'document_name',
        'document_number',
        'document_date',
        'is_bast',
        'no_surat_bast',
        'bast_date',
        'project_name',
        'project_amount',
        'amount_bond',
        'bond_percentage',
        'start_period_date',
        'end_period_date',
        'tgl_surat_perjanjian',
        'no_surat_perjanjian',
        'total_day',
        'province',
        'tarif_percentage',
        'ijp_amount',
        'administrative_amount',
        'jenis_surat_perjanjian',
        'stamp_amount',
        'cogar_type',
        'cogar_company',
        'cogar_percentage',
        'id_institution',
        'principal_name',
        'obligee_name',
        'is_deposit',

    ];

    public function penjaminanHeader()
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no')
            ->withDefault(['trx_no' => null]);
    }
}
