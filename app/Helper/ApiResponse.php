<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        $data = null,
        string $message = 'Success',
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }

    public static function error(
        string $message = 'Error',
        int $code = 400,
        $errors = null
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'data'    => null,
        ];

        if (!is_null($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $code);
    }

    public static function validation($errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation error',
            'data'    => null,
            'errors'  => $errors,
        ], 422);
    }
}