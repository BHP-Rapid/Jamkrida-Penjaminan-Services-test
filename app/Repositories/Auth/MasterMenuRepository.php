<?php

namespace App\Repositories;

use App\Models\MasterMenu;
use Illuminate\Database\Eloquent\Collection;

class MasterMenuRepository
{
    public function findById(int|string $id): ?MasterMenu
    {
        return MasterMenu::query()->find($id);
    }

    public function findByCode(string $menuCode): ?MasterMenu
    {
        return MasterMenu::query()
            ->where('menu_code', $menuCode)
            ->first();
    }

    public function findByWebType(string $webType): Collection
    {
        return MasterMenu::query()
            ->where('web_type', $webType)
            ->where('is_active', true)
            ->orderBy('order_index')
            ->get();
    }

    public function findByIdsAndWebType(array $ids, string $webType): Collection
    {
        return MasterMenu::query()
            ->whereIn('id', $ids)
            ->where('web_type', $webType)
            ->orderBy('order_index')
            ->get();
    }

    public function findRoleManagementMenu(string $webType): ?MasterMenu
    {
        return MasterMenu::query()
            ->where('web_type', $webType)
            ->where('path', '/portal-admin/role-management')
            ->first();
    }
}
