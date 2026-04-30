<?php

namespace App\Http\Middleware;

use App\Services\AuthInternalClient;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateAuthContext
{
    public function __construct(
        protected AuthInternalClient $authInternalClient,
    ) {}

    public function handle(Request $request, Closure $next): Response
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
            $response = $this->authInternalClient->context($userToken);
            $authUser = $response['data'] ?? null;
            if (! is_array($authUser)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized: user context tidak valid.',
                    'errors' => [],
                    'data' => $response,
                ], 401);
            }
            $request->attributes->set('auth_user', $authUser);
            $request->attributes->set('auth_token', $userToken);
            $request->attributes->set('auth_user_id', $authUser['user_id'] ?? null);
            $request->attributes->set('auth_role', $authUser['role'] ?? null);

            return $next($request);
        } catch (RequestException $exception) {
            $response = $exception->response;

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: gagal mengambil user context.',
                'errors' => [],
                'data' => $response?->json(),
            ], $response?->status() ?? 401);
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: ' . $exception->getMessage(),
                'errors' => [],
                'data' => null,
            ], 401);
        }
    }
}
