<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HorizonSessionAuthMiddleware
{
    public const SESSION_KEY = 'horizon_authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) $request->session()->get(self::SESSION_KEY, false)) {
            return $next($request);
        }

        if ($this->isHorizonApiRequest($request)) {
            abort(401, 'Unauthenticated.');
        }

        return redirect()->guest(self::horizonUrl('login'));
    }

    /**
     * Build a browser-facing URL for a Horizon sub-path.
     *
     * Uses url() which already includes the APP_URL base (with any proxy
     * prefix like /penjaminan-test). No need to manually prepend proxy_path
     * since APP_URL already accounts for it.
     */
    public static function horizonUrl(string $subPath = ''): string
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        $path = $horizonPath;

        if ($subPath !== '') {
            $path .= '/'.ltrim($subPath, '/');
        }

        return url($path);
    }

    private function isHorizonApiRequest(Request $request): bool
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');
        $apiPath = $horizonPath !== '' ? $horizonPath.'/api/*' : 'api/*';

        return $request->expectsJson() || $request->is($apiPath);
    }
}
