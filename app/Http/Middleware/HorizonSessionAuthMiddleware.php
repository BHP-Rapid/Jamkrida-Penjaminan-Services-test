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
     * Includes horizon.proxy_path when Horizon is exposed below a reverse
     * proxy prefix such as /penjaminan-test.
     */
    public static function horizonUrl(string $subPath = ''): string
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');
        $proxyPath = trim((string) config('horizon.proxy_path', ''), '/');
        $basePath = trim((string) parse_url(url('/'), PHP_URL_PATH), '/');

        if ($proxyPath !== '' && ($basePath === $proxyPath || str_ends_with($basePath, '/'.$proxyPath))) {
            $proxyPath = '';
        }

        $path = collect([$proxyPath, $horizonPath, ltrim($subPath, '/')])
            ->filter(fn (string $segment): bool => $segment !== '')
            ->implode('/');

        return url($path);
    }

    private function isHorizonApiRequest(Request $request): bool
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');
        $apiPath = $horizonPath !== '' ? $horizonPath.'/api/*' : 'api/*';

        return $request->expectsJson() || $request->is($apiPath);
    }
}
