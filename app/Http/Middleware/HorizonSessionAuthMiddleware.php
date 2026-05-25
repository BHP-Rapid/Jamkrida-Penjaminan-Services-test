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

        return redirect()->guest(self::proxyUrl('login'));
    }

    /**
     * Build a browser-facing URL for a Horizon sub-path, prepending the
     * reverse-proxy prefix when configured.
     */
    public static function proxyUrl(string $subPath = ''): string
    {
        $proxyPath = trim((string) config('horizon.proxy_path', ''), '/');
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');

        $base = $proxyPath !== '' ? $proxyPath.'/'.$horizonPath : $horizonPath;

        if ($subPath !== '') {
            $base .= '/'.ltrim($subPath, '/');
        }

        return url($base);
    }

    private function isHorizonApiRequest(Request $request): bool
    {
        $horizonPath = trim((string) config('horizon.path', 'horizon'), '/');
        $apiPath = $horizonPath !== '' ? $horizonPath.'/api/*' : 'api/*';

        return $request->expectsJson() || $request->is($apiPath);
    }
}
