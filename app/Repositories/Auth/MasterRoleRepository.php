<?php

namespace App\Repositories;

use App\Models\MasterRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MasterRoleRepository
{
    public function findById(int|string $id): ?MasterRole
    {
        return MasterRole::query()->find($id);
    }

    public function findByIdentifier(string $identifier): ?MasterRole
    {
        return MasterRole::query()
            ->where('role_code', $identifier)
            ->orWhere('role_name', $identifier)
            ->first();
    }

    public function paginate(array $filters, string $sortColumn, string $sortOrder, int $perPage): LengthAwarePaginator
    {
        return MasterRole::query()
            ->when(! empty($filters['role_code']), fn (Builder $query) => $query->where('role_code', 'like', '%'.$filters['role_code'].'%'))
            ->when(! empty($filters['role_name']), fn (Builder $query) => $query->where('role_name', 'like', '%'.$filters['role_name'].'%'))
            ->when(! empty($filters['type']), fn (Builder $query) => $query->where('type', 'like', '%'.$filters['type'].'%'))
            ->orderBy($sortColumn, $sortOrder)
            ->paginate($perPage);
    }

    public function findByType(string $type): Collection
    {
        return MasterRole::query()
            ->where('type', $type)
            ->select('role_code as value', 'role_name as label')
            ->get();
    }

    public function findCodesByType(string $type): array
    {
        return MasterRole::query()
            ->where('type', $type)
            ->pluck('role_code')
            ->toArray();
    }
}
