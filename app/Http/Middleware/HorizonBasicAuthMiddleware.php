<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HorizonBasicAuthMiddleware
{
    /**
     * Protect Horizon dashboard with HTTP Basic Auth.
     *
     * Credentials are read from HORIZON_AUTH_USER and HORIZON_AUTH_PASSWORD
     * environment variables. If either is not set, access is denied.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = config('horizon.auth.user');
        $expectedPassword = config('horizon.auth.password');

        if (! $expectedUser || ! $expectedPassword) {
            abort(403, 'Horizon authentication is not configured.');
        }

        $givenUser = $request->getUser();
        $givenPassword = $request->getPassword();

        if ($givenUser === $expectedUser && $givenPassword === $expectedPassword) {
            return $next($request);
        }

        return new Response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="Horizon"',
        ]);
    }
}
