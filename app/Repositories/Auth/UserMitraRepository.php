<?php

namespace App\Repositories;

use App\Models\UserMitra;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserMitraRepository
{
    public function findById(int|string $id): ?UserMitra
    {
        return UserMitra::query()->find($id);
    }

    public function findByUserId(string $userId): ?UserMitra
    {
        return UserMitra::query()
            ->where('user_id', $userId)
            ->first();
    }

    public function findPublicIdentityByUserId(string $userId): ?object
    {
        return UserMitra::query()
            ->select(['user_mitra.user_id', 'm.mitra_id'])
            ->leftJoin('mitra as m', 'user_mitra.mitra_id', '=', 'm.mitra_id')
            ->where('user_mitra.user_id', $userId)
            ->first();
    }

    public function findForLoginByUserId(string $userId): ?UserMitra
    {
        return UserMitra::query()
            ->leftJoin('tenant_mitra', 'tenant_mitra.mitra_id', '=', 'user_mitra.mitra_id')
            ->where('user_mitra.user_id', $userId)
            ->select(
                'user_mitra.*',
                'tenant_mitra.is_conventional',
                'tenant_mitra.is_syariah',
                DB::raw("CASE
                    WHEN tenant_mitra.is_conventional = 1 AND tenant_mitra.is_syariah = 0 THEN 'conventional'
                    WHEN tenant_mitra.is_syariah = 1 AND tenant_mitra.is_conventional = 0 THEN 'syariah'
                    WHEN tenant_mitra.is_conventional = 1 AND tenant_mitra.is_syariah = 1 THEN 'both'
                    ELSE 'unknown'
                END as tipe_metod_mitra")
            )
            ->first();
    }

    public function incrementLoginAttempts(UserMitra $user, int $maxAttempts, int $suspendSeconds): int
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

    public function resetLoginAttempts(UserMitra $user): void
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

    public function findByEmail(string $email): ?UserMitra
    {
        return UserMitra::query()
            ->where('email', $email)
            ->first();
    }

    public function findActiveUsers(): Collection
    {
        return UserMitra::query()
            ->where(fn (Builder $query) => $query->where('is_delete', false)->orWhereNull('is_delete'))
            ->get();
    }

    public function paginateUsers(array $payload): LengthAwarePaginator
    {
        $mitraIdAliasExpr = "COALESCE(NULLIF(tenant_mitra.alias, ''), user_mitra.mitra_id)";

        $query = UserMitra::query()
            ->leftJoin('tenant_mitra', 'tenant_mitra.mitra_id', '=', 'user_mitra.mitra_id')
            ->select(
                'user_mitra.name',
                'user_mitra.email',
                'user_mitra.user_id',
                'user_mitra.status',
                'user_mitra.statusApproval',
                'user_mitra.role',
                'tenant_mitra.mitra_id as mitra_code'
            )
            ->selectRaw($mitraIdAliasExpr.' as mitra_id')
            ->where(function (Builder $query): void {
                $query->where('user_mitra.is_delete', false)
                    ->orWhereNull('user_mitra.is_delete');
            });

        foreach (($payload['filter'] ?? []) as $filterItem) {
            $filterId = $filterItem['id'] ?? null;
            $filterValue = $filterItem['value'] ?? null;

            switch ($filterId) {
                case 'mitra_id':
                    $query->whereRaw($mitraIdAliasExpr." like ?", ['%'.$filterValue.'%']);
                    break;
                case 'name':
                    $query->where('user_mitra.name', 'like', '%'.$filterValue.'%');
                    break;
                case 'user_id':
                    $query->where('user_mitra.user_id', 'like', '%'.$filterValue.'%');
                    break;
                case 'email':
                    $query->where('user_mitra.email', 'like', '%'.$filterValue.'%');
                    break;
                case 'role':
                    $query->where('user_mitra.role', 'like', '%'.$filterValue.'%');
                    break;
                case 'status':
                    is_array($filterValue)
                        ? $query->whereIn('user_mitra.status', $filterValue)
                        : $query->where('user_mitra.status', 'like', '%'.$filterValue.'%');
                    break;
                case 'approval':
                    is_array($filterValue)
                        ? $query->whereIn('user_mitra.statusApproval', $filterValue)
                        : $query->where('user_mitra.statusApproval', 'like', '%'.$filterValue.'%');
                    break;
                default:
                    break;
            }
        }

        $sortable = [
            'mitra_id' => $mitraIdAliasExpr,
            'name' => 'user_mitra.name',
            'user_id' => 'user_mitra.user_id',
            'email' => 'user_mitra.email',
            'role' => 'user_mitra.role',
            'status' => 'user_mitra.status',
            'approval' => 'user_mitra.statusApproval',
            'created_at' => 'user_mitra.created_at',
        ];

        $sortColumn = $payload['sort_column'] ?? null;
        $sortDirection = $payload['sort'] ?? null;

        if ($sortColumn && $sortDirection && isset($sortable[$sortColumn])) {
            if ($sortColumn === 'mitra_id') {
                $query->orderByRaw($sortable[$sortColumn].' '.$sortDirection);
            } else {
                $query->orderBy($sortable[$sortColumn], $sortDirection);
            }
        } else {
            $query->orderBy('user_mitra.created_at', 'desc');
        }

        return $query
            ->paginate((int) ($payload['show_page'] ?? 10))
            ->appends($payload);
    }

    public function findVerificationUsersForActor(object $actor): Collection
    {
        $mitraIdAliasExpr = "COALESCE(NULLIF(tenant_mitra.alias, ''), user_mitra.mitra_id)";

        $query = UserMitra::query()
            ->leftJoin('tenant_mitra', 'tenant_mitra.mitra_id', '=', 'user_mitra.mitra_id')
            ->where('user_mitra.statusApproval', 'Submitted')
            ->select(
                'user_mitra.name',
                'user_mitra.email',
                'user_mitra.status',
                'user_mitra.user_id',
                'user_mitra.phone',
                'user_mitra.role',
                'tenant_mitra.mitra_id as mitra_code'
            )
            ->selectRaw($mitraIdAliasExpr.' as mitra_id');

        if (($actor->role ?? null) !== 'super_admin') {
            $query->where('user_mitra.mitra_id', $actor->mitra_id ?? null);
        }

        return $query->get();
    }

    public function create(array $payload): UserMitra
    {
        return UserMitra::query()->create($payload);
    }

    public function countByMitraId(string $mitraId): int
    {
        return UserMitra::query()->where('mitra_id', $mitraId)->count();
    }

    public function findLatestUserIdByMitraId(string $mitraId): ?string
    {
        return UserMitra::query()
            ->where('mitra_id', $mitraId)
            ->orderBy('user_id', 'desc')
            ->value('user_id');
    }

    public function existsByUserId(string $userId): bool
    {
        return UserMitra::query()->where('user_id', $userId)->exists();
    }

    public function lockByUserId(string $userId): ?UserMitra
    {
        return UserMitra::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    public function updateByUserId(string $userId, array $attributes): bool
    {
        return UserMitra::query()
            ->where('user_id', $userId)
            ->update($attributes) > 0;
    }

    public function findDetailedByUserId(string $userId): ?object
    {
        return DB::table('user_mitra as a')
            ->join('tenant_mitra as m', 'a.mitra_id', '=', 'm.mitra_id')
            ->select('a.role', 'm.mitra_id', 'a.name', 'a.phone', 'a.email')
            ->where('a.user_id', $userId)
            ->first();
    }

    public function updatePassword(UserMitra $user, string $hashedPassword): void
    {
        $user->password = $hashedPassword;
        $user->save();
    }
}
