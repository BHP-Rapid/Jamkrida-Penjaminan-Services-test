<?php

namespace App\Http\Middleware;

use App\Services\AuthInternalClient;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckAuthPermission
{
    public function __construct(
        protected AuthInternalClient $authInternalClient,
    ) {}

    public function handle(Request $request, Closure $next, string $menuIdentifier, string ...$actions): Response
    {
        $userToken = (string) $request->bearerToken();

        if ($userToken === '') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: bearer token user wajib dikirim.',
                'errors' => [],
                'data' => null,
            ], 401);
        }

        try {
            $actions = $actions === [] ? ['view'] : $actions;
            $response = $this->authInternalClient->checkPermission($menuIdentifier, $actions, $userToken);
            $allowed = (bool) ($response['data']['allowed'] ?? false);

            if (! $allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden: insufficient permission.',
                    'errors' => [],
                    'data' => $response['data'] ?? null,
                ], 403);
            }

            return $next($request);
        } catch (RequestException $exception) {
            $response = $exception->response;

            return response()->json([
                'success' => false,
                'message' => 'Forbidden: gagal check permission ke auth service.',
                'errors' => [],
                'data' => $response?->json(),
            ], $response?->status() ?? 403);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: ' . $exception->getMessage(),
                'errors' => [],
                'data' => null,
            ], 403);
        }
    }
}
