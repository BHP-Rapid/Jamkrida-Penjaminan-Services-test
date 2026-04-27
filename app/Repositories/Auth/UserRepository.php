<?php

namespace App\Repositories;

use App\Models\Mitra;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserRepository
{
    public function findById(int|string $id): ?User
    {
        return User::query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->first();
    }

    public function findByUserId(int|string $userId): ?User
    {
        $query = User::query();

        if (Schema::hasColumn((new User())->getTable(), 'user_id')) {
            return $query->where('user_id', $userId)->first();
        }

        return $query->find($userId);
    }

    public function incrementLoginAttempts(User $user, int $maxAttempts, int $suspendSeconds): int
    {
        if (! Schema::hasColumn($user->getTable(), 'login_attempts')) {
            return 0;
        }

        $user->login_attempts = ((int) $user->login_attempts) + 1;
        $remainingAttempts = max($maxAttempts - (int) $user->login_attempts, 0);

        if (
            $maxAttempts > 0
            && $user->login_attempts >= $maxAttempts
            && Schema::hasColumn($user->getTable(), 'suspend_until')
        ) {
            $user->suspend_until = now()->addSeconds($suspendSeconds);
            $user->login_attempts = 0;
            $remainingAttempts = 0;
        }

        $user->save();

        return $remainingAttempts;
    }

    public function resetLoginAttempts(User $user): void
    {
        $dirty = false;

        if (Schema::hasColumn($user->getTable(), 'login_attempts')) {
            $user->login_attempts = 0;
            $dirty = true;
        }

        if (Schema::hasColumn($user->getTable(), 'suspend_until')) {
            $user->suspend_until = null;
            $dirty = true;
        }

        if (Schema::hasColumn($user->getTable(), 'last_login')) {
            $user->last_login = now();
            $dirty = true;
        }

        if ($dirty) {
            $user->save();
        }
    }

    public function updatePassword(User $user, string $hashedPassword): void
    {
        $user->password = $hashedPassword;
        $user->save();
    }

    public function findActiveUsers(): Collection
    {
        return User::query()
            ->where(fn (Builder $query) => $query->where('is_delete', false)->orWhereNull('is_delete'))
            ->get();
    }

    public function getUsersByRoleForAdmin(object $actor, array $payload): LengthAwarePaginator
    {
        $mitraIdAliasExpr = "COALESCE(NULLIF(tenant_mitra.alias, ''), users.mitra_id)";

        $query = User::query()
            ->leftJoin('tenant_mitra', 'tenant_mitra.mitra_id', '=', 'users.mitra_id')
            ->where(function ($builder): void {
                $builder->where('users.is_delete', false)
                    ->orWhereNull('users.is_delete');
            })
            ->select('users.*')
            ->selectRaw($mitraIdAliasExpr.' as mitra_id');

        if (($actor->role ?? null) === 'admin' && ($actor->mitra_id ?? null) === 'JMKRD') {
            $query->where('users.role', 'admin_mitra');
        }

        $this->applyUserFilters($query, $payload['filter'] ?? [], $mitraIdAliasExpr, true);
        $this->applyUserSorting($query, $payload, $mitraIdAliasExpr);

        return $query
            ->paginate((int) ($payload['show_page'] ?? 10))
            ->appends($payload);
    }

    public function getVerificationUsers(object $actor, array $payload): LengthAwarePaginator
    {
        $mitraIdAliasExpr = "COALESCE(NULLIF(tenant_mitra.alias, ''), users.mitra_id)";

        $query = User::query()
            ->leftJoin('tenant_mitra', 'tenant_mitra.mitra_id', '=', 'users.mitra_id')
            ->where('users.status_approval', 'submitted')
            ->select('users.*')
            ->selectRaw($mitraIdAliasExpr.' as mitra_id');

        if (($actor->role ?? null) === 'admin' && ($actor->mitra_id ?? null) === 'JMKRD') {
            $query->where('users.role', 'admin_mitra');
        }

        if (! empty($payload['search'])) {
            $search = $payload['search'];
            $query->where(function ($builder) use ($search, $mitraIdAliasExpr): void {
                $builder->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.email', 'like', '%'.$search.'%')
                    ->orWhereRaw($mitraIdAliasExpr." like ?", ['%'.$search.'%'])
                    ->orWhere('users.phone', 'like', '%'.$search.'%')
                    ->orWhere('users.role', 'like', '%'.$search.'%');
            });
        }

        $this->applyUserFilters($query, $payload['filter'] ?? [], $mitraIdAliasExpr, false);
        $this->applyUserSorting($query, $payload, $mitraIdAliasExpr);

        return $query
            ->paginate((int) ($payload['show_page'] ?? 10))
            ->appends($payload);
    }

    public function create(array $payload): User
    {
        return User::query()->create($payload);
    }

    public function countByMitraId(string $mitraId): int
    {
        return User::query()->where('mitra_id', $mitraId)->count();
    }

    public function existsByUserId(string $userId): bool
    {
        return User::query()->where('user_id', $userId)->exists();
    }

    public function lockByUserId(string $userId): ?User
    {
        return User::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    public function updateByUserId(string $userId, array $attributes): bool
    {
        return User::query()
            ->where('user_id', $userId)
            ->update($attributes) > 0;
    }

    public function getAdminMitraList(): Collection
    {
        return Mitra::query()
            ->selectRaw('mitra_id as value')
            ->selectRaw('name_mitra as label')
            ->get();
    }

    protected function applyUserFilters(Builder $query, array $filters, string $mitraIdAliasExpr, bool $includeApproval = true): void
    {
        foreach ($filters as $filterItem) {
            $filterId = $filterItem['id'] ?? null;
            $filterValue = $filterItem['value'] ?? null;

            switch ($filterId) {
                case 'name':
                    $query->where('users.name', 'like', '%'.$filterValue.'%');
                    break;
                case 'mitra_id':
                    $query->whereRaw($mitraIdAliasExpr." like ?", ['%'.$filterValue.'%']);
                    break;
                case 'email':
                    $query->where('users.email', 'like', '%'.$filterValue.'%');
                    break;
                case 'phone':
                    $query->where('users.phone', 'like', '%'.$filterValue.'%');
                    break;
                case 'role':
                    $query->where('users.role', 'like', '%'.$filterValue.'%');
                    break;
                case 'status':
                    is_array($filterValue)
                        ? $query->whereIn('users.status', $filterValue)
                        : $query->where('users.status', 'like', '%'.$filterValue.'%');
                    break;
                case 'approval':
                    if ($includeApproval) {
                        is_array($filterValue)
                            ? $query->whereIn('users.status_approval', $filterValue)
                            : $query->where('users.status_approval', 'like', '%'.$filterValue.'%');
                    }
                    break;
                default:
                    break;
            }
        }
    }

    protected function applyUserSorting(Builder $query, array $payload, string $mitraIdAliasExpr): void
    {
        $sortable = [
            'name' => 'users.name',
            'mitra_id' => $mitraIdAliasExpr,
            'email' => 'users.email',
            'phone' => 'users.phone',
            'role' => 'users.role',
            'status' => 'users.status',
            'approval' => 'users.status_approval',
        ];

        $sortColumn = $payload['sort_column'] ?? null;
        $sortDirection = $payload['sort'] ?? null;

        if ($sortColumn && $sortDirection && isset($sortable[$sortColumn])) {
            if ($sortColumn === 'mitra_id') {
                $query->orderByRaw($sortable[$sortColumn].' '.$sortDirection);
            } else {
                $query->orderBy($sortable[$sortColumn], $sortDirection);
            }

            return;
        }

        $query->orderBy('users.created_at', 'desc');
    }
}
