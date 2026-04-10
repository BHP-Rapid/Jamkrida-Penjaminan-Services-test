<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenjaminanFlow extends Model
{
    use HasFactory;

    protected $table = 'penjaminan_flow';

    protected $fillable = [
        'trx_no',
        'trx_status',
        'reason',
        'created_at',
        'created_by_name',
        'created_by_id',
        'updated_at',
        'updated_by_name',
        'updated_by_id',
    ];

    public function penjaminanHeader()
    {
        return $this->belongsTo(PenjaminanTransaction::class, 'trx_no', 'trx_no')
            ->withDefault(['trx_no' => null]);
    }
}
