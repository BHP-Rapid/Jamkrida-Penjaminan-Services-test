<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class AesHelper
{
    public static function encrypt($plaintext, $key)
    {
        $iv = random_bytes(openssl_cipher_iv_length('AES-256-CBC'));

        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-CBC',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );

        $hmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
        
        return base64_encode($iv . $hmac . $ciphertext);
    }

    public static function decrypt($payload, $key, $field = null)
    {
        try {
            $data = base64_decode($payload);

            $ivLength = openssl_cipher_iv_length('AES-256-CBC');
            $iv   = substr($data, 0, $ivLength);
            $hmac = substr($data, $ivLength, 32);
            $ciphertext = substr($data, $ivLength + 32);

            $calcHmac = hash_hmac('sha256', $iv . $ciphertext, $key, true);

            if (!hash_equals($hmac, $calcHmac)) {
                // throw new \RuntimeException("Integrity check failed (HMAC mismatch) field {$field}");
                return $payload;
            }

            return openssl_decrypt(
                $ciphertext,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
        } catch (\Throwable $e) {
            Log::error('GLOBAL DECRYPT FAIL', [
                'value' => substr((string)$payload, 0, 30),
                'trace' => collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10))
                    ->map(fn ($t) => ($t['file'] ?? '') . ':' . ($t['line'] ?? ''))
                    ->toArray(),
            ]);

            throw $e; // biar behaviour lama tetap
        }
    }
}
