<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(
                message: 'Unauthorized: user not found.',
                status: 401,
            );
        }

        if (isset($user->role) && in_array((string) $user->role, $roles, true)) {
            return $next($request);
        }

        if (isset($user->role_id) && in_array((string) $user->role_id, $roles, true)) {
            return $next($request);
        }

        return ApiResponse::error(
            message: 'Forbidden: insufficient role.',
            status: 403,
        );
    }
}
