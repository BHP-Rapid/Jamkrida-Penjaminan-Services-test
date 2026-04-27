<?php

namespace App\Http\Middleware;

use App\Helpers\ApiResponse;
use App\Repositories\UserMitraRepository;
use App\Repositories\UserRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthenticateJwt
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserMitraRepository $userMitraRepository,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $payload = JWTAuth::parseToken()->getPayload();
            $authType = (string) $payload->get('auth_type', 'admin');
            $subject = $payload->get('sub');

            $user = match ($authType) {
                'mitra' => $this->userMitraRepository->findById($subject),
                default => $this->userRepository->findById($subject),
            };

            if (! $user) {
                return ApiResponse::error(
                    message: 'Unauthorized: user not found.',
                    status: 401,
                );
            }

            Auth::shouldUse('api');
            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            $request->attributes->set('auth_type', $authType);

            return $next($request);
        } catch (TokenExpiredException) {
            return ApiResponse::error(
                message: 'Unauthorized: token expired.',
                status: 401,
            );
        } catch (TokenInvalidException) {
            return ApiResponse::error(
                message: 'Unauthorized: token invalid.',
                status: 401,
            );
        } catch (JWTException) {
            return ApiResponse::error(
                message: 'Unauthorized: token missing.',
                status: 401,
            );
        }
    }
}
