<?php

namespace App\Repositories;

use App\Models\MasterMenuRoleMapping;
use Illuminate\Database\Eloquent\Collection;

class MasterMenuRoleMappingRepository
{
    public function findByRoleAndMenu(int|string $roleId, int|string $menuIdentifier): ?MasterMenuRoleMapping
    {
        return MasterMenuRoleMapping::query()
            ->where('role_id', $roleId)
            ->when(
                is_numeric($menuIdentifier),
                fn ($query) => $query->where('menu_id', $menuIdentifier),
                fn ($query) => $query->whereHas('menu', fn ($menuQuery) => $menuQuery->where('menu_code', $menuIdentifier))
            )
            ->first();
    }

    public function hasPermission(int|string $roleId, int|string $menuIdentifier, string $action): bool
    {
        $mapping = $this->findByRoleAndMenu($roleId, $menuIdentifier);

        if (! $mapping) {
            return false;
        }

        return match ($action) {
            'view' => (bool) $mapping->can_view,
            'create' => (bool) $mapping->can_create,
            'edit' => (bool) $mapping->can_edit,
            'delete' => (bool) $mapping->can_delete,
            'approve' => (bool) $mapping->can_approve,
            default => false,
        };
    }

    public function findByRoleId(int|string $roleId): Collection
    {
        return MasterMenuRoleMapping::query()
            ->with('menu')
            ->where('role_id', $roleId)
            ->get();
    }

    public function updatePermissions(int|string $roleId, int|string $menuId, array $actions): MasterMenuRoleMapping
    {
        return MasterMenuRoleMapping::query()->updateOrCreate(
            [
                'role_id' => $roleId,
                'menu_id' => $menuId,
            ],
            [
                'can_view' => in_array('view', $actions, true),
                'can_create' => in_array('create', $actions, true),
                'can_edit' => in_array('edit', $actions, true),
                'can_delete' => in_array('delete', $actions, true),
                'can_approve' => in_array('approve', $actions, true),
            ],
        );
    }
}
