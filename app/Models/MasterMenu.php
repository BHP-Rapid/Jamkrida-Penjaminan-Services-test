<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterMenu extends Model
{
    protected $table = 'master_menus_v2';

    protected $primaryKey = 'id';

    protected $fillable = [
        'menu_code',
        'parent_id',
        'title',
        'trans_key',
        'path',
        'icon',
        'nav_type',
        'web_type',
        'order_index',
        'available_actions',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'available_actions' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    // public function roleMappings(): HasMany
    // {
    //     return $this->hasMany(MasterMenuRoleMapping::class, 'menu_id', 'id');
    // }
}
