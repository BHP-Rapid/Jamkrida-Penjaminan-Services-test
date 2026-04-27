<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterMenuRoleMapping extends Model
{
    protected $table = 'master_menu_role_mapping_v2';

    protected $primaryKey = 'id';

    protected $fillable = [
        'role_id',
        'menu_id',
        'can_view',
        'can_create',
        'can_edit',
        'can_delete',
        'can_approve',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_create' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'can_approve' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(MasterMenu::class, 'menu_id', 'id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(MasterRole::class, 'role_id', 'id');
    }
}
