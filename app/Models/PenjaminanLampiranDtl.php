<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use App\Helpers\AesHelper;

class PenjaminanLampiranDtl extends Model
{
    use HasFactory;

    protected $table = 'penjaminan_lampiran_dtl';

    protected $fillable = [
        'trx_no', 
        'file_id',
        'lampiran_id',
        'file_name',
        'file_info', 
        'status_doc',
        'version',
        'mime_type',
        'data_base64',
        'is_additional'
    ];

    // protected function fileName(): Attribute
    // {
    //     $key = base64_decode(config('services.secure.key'));

    //     return Attribute::make(
    //         get: fn($value) => $value ? AesHelper::decrypt($value, $key) : null,
    //         set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
    //     );
    // }

    // protected function data_base64(): Attribute
    // {
    //     $key = base64_decode(config('services.secure.key'));

    //     return Attribute::make(
    //         get: fn($value) => $value ? AesHelper::decrypt($value, $key) : null,
    //         set: fn($value) => $value ? AesHelper::encrypt($value, $key) : null,
    //     );
    // }
}
