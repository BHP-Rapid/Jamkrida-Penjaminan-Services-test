<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mitra extends Model
{

    protected $table = 'mitra';

    protected $fillable = [
        'mitra_id',
        'name_mitra',
        'email',
        'phone_number',
        'address',
        'status',
        'filename',
        'fileinfo',
        'data_base64',
        'mime_type'
    ];

}
