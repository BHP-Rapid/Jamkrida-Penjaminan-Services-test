<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    // public static function success(
    //     $data = null,
    //     string $message = 'Success',
    //     int $code = 200
    // ): JsonResponse {
    //     return response()->json([
    //         'success' => true,
    //         'message' => $message,
    //         'data'    => $data,
    //     ], $code);
    // }

    // public static function error(
    //     string $message = 'Error',
    //     int $code = 400,
    //     $errors = null
    // ): JsonResponse {
    //     $payload = [
    //         'success' => false,
    //         'message' => $message,
    //         'data'    => null,
    //     ];

    //     if (!is_null($errors)) {
    //         $payload['errors'] = $errors;
    //     }

    //     return response()->json($payload, $code);
    // }

    // public static function validation($errors): JsonResponse
    // {
    //     return response()->json([
    //         'success' => false,
    //         'message' => 'Validation error',
    //         'data'    => null,
    //         'errors'  => $errors,
    //     ], 422);
    // }

    public static function success(
        mixed $data = null,
        string $message = 'Request berhasil diproses.',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(
        string $message = 'Terjadi kesalahan pada server.',
        int $status = 500,
        array $errors = [],
        mixed $data = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'data' => $data,
        ], $status);
    }
}
