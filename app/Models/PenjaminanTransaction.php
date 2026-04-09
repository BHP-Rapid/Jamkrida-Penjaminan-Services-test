<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenjaminanTransaction extends Model
{
    use HasFactory;

    protected $table = 'transaction_penjaminan_header';
    protected $primaryKey = 'trx_no';
    protected $keyType = 'string';

    protected $fillable = [
        'trx_no',
        'no_surat_permohonan',
        'tanggal_surat_permohonan',
        'product',
        'mitra_id',
        'sp_split',
        'trx_status',
        'status_sync_creatio',
        'no_rek',
        'created_by_id',
        'no_rek',
        'created_by_name',
        'updated_by_id',
        'updated_by_name',
        'created_at',
        'updated_at',
    ];

    // public function customBondTransaction()
    // {
    //     return $this->hasMany(CustomBondTransaction::class, 'trx_no', 'trx_no');
    // }

    // public function suretyBondTransaction()
    // {
    //     return $this->hasMany(SuretyBondTransaction::class, 'trx_no', 'trx_no');
    // }

    // public function multigunaTransaction()
    // {
    //     return $this->hasMany(MultigunaTransaction::class, 'trx_no', 'trx_no');
    // }

    // public function penjaminanFlowTransaction()
    // {
    //     return $this->hasMany(PenjaminanFlow::class, 'trx_no', 'trx_no');
    // }

    // public function payments()
    // {
    //     return $this->hasMany(TrxInvoiceHeader::class, 'trx_no', 'trx_no');
    // }
}
