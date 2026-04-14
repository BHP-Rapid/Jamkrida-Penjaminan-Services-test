<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BulkUploadPenjaminanHdr extends Model
{
    protected $table = 'bulk_upload_penjaminan_hdr';

    protected $fillable = [
        'upload_id',
        'id_mitra',
        'mitra_name',
        'count_data_upload',
        'jenis_produk_name',
        'jenis_produk_code',
        'status',
        'status_sync_creatio',
        'created_by_name',
        'created_by_id',
        'updated_by_name',
        'updated_by_id'
    ];
    
}
