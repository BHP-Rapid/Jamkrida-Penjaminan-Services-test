<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotifMitra extends Model
{
    use HasFactory;

    protected $table = 'notif_mitra';
    public $timestamps = true;

    protected $fillable = [
        'mitra_user_id',
        'title',
        'message',
        'type',
        'is_read',
    ];

}
