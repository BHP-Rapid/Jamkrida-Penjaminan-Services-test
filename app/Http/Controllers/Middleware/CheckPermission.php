<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Helpers\MenuPermissionHelper;
use App\Repositories\MasterMenuRoleMappingRepository;
use App\Repositories\MasterRoleRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function __construct(
        protected MenuPermissionHelper $menuPermissionHelper,
        protected MasterRoleRepository $masterRoleRepository,
        protected MasterMenuRoleMappingRepository $masterMenuRoleMappingRepository,
    ) {
    }

    public function handle(Request $request, Closure $next, string $menuIdentifier, string $action = 'view'): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(
                message: 'Unauthorized: user not found.',
                status: 401,
            );
        }

        $roleId = $this->resolveRoleId($user);

        if ($roleId === null) {
            return ApiResponse::error(
                message: 'Forbidden: role mapping not found.',
                status: 403,
            );
        }

        $menuId = $this->menuPermissionHelper->resolveMenuId($menuIdentifier);

        if ($menuId === null) {
            return ApiResponse::error(
                message: 'Forbidden: menu mapping not found.',
                status: 403,
            );
        }

        if (! $this->masterMenuRoleMappingRepository->hasPermission($roleId, $menuId, $action)) {
            return ApiResponse::error(
                message: 'Forbidden: insufficient permission.',
                status: 403,
            );
        }

        return $next($request);
    }

    protected function resolveRoleId(object $user): int|string|null
    {
        if (isset($user->role_id) && $user->role_id !== null && $user->role_id !== '') {
            return $user->role_id;
        }

        if (! isset($user->role) || $user->role === null || $user->role === '') {
            return null;
        }

        if (is_numeric($user->role)) {
            return $user->role;
        }

        return $this->masterRoleRepository->findByIdentifier((string) $user->role)?->getKey();
    }
}
