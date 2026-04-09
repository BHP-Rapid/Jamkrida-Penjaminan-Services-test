<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantMitra extends Model
{
    protected $table = 'tenant_mitra';
    protected $primaryKey = 'id';

    protected $fillable = [
        'mitra_id',
        'tenant_id',
        'institution_id',
        'parent_id',
        'name',
        'is_syariah',
        'is_conventional',
        'logo',
        'primary_color',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at',
        'alias'
    ];
}
