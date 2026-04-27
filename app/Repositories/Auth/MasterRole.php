<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterRole extends Model
{
    use HasFactory;

    protected $table = 'master_role';

    protected $fillable = [
        'id',
        'role_code',
        'role_name',
        'type',
    ];

    public function menuMappings(): HasMany
    {
        return $this->hasMany(MasterMenuRoleMapping::class, 'role_id', 'id');
    }
}
