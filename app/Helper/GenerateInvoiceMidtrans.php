<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Midtrans\Config;
use Midtrans\Snap;

class GenerateInvoiceMidtrans
{
    public static function checkSnapStatus(string $orderId): array
    {
        if (empty($orderId)) {
            return [
                'success' => false,
                'message' => 'order_id wajib diisi',
                'status'  => 422,
            ];
        }

        $isProd    = (bool) config('midtrans.is_production', false);
        $baseUrl   = $isProd ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
        $serverKey = config('midtrans.server_key');

        $response = Http::withBasicAuth($serverKey, '')
            ->acceptJson()
            ->get("$baseUrl/v1/" . urlencode($orderId) . "/status");
        if ($response->failed()) {
            return [
                'success' => false,
                'message' => 'Gagal mendapatkan status transaksi',
                'status'  => $response->status(),
                'errors'  => $response->json() ?: $response->body(),
            ];
        }

        $tx     = $response->json();
        $raw    = $tx['transaction_status'] ?? null;
        $mapped = match ($raw) {
            'settlement' => 'paid',
            'pending'    => 'pending',
            'expire'     => 'expired',
            'cancel', 'deny', 'failure' => 'failed',
            default      => 'unknown',
        };

        return [
            'success' => true,
            'message' => 'Berhasil mengambil status Snap',
            'status'  => $mapped,
            'raw'     => $tx,
        ];
    }

    public static function PaymentExpired($payment)
    {
        if (Carbon::now()->greaterThan(Carbon::parse($payment->expiry_date_time))) {
            return 'expired';
        } else {
            return 'pending';
        }
    }

    public static function createSnapToken(array $params): array
    {
        $isProd    = (bool) config('midtrans.is_production', false);
        $baseUrl   = $isProd ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        $serverKey = config('midtrans.server_key');

        $endpoint = $baseUrl . '/snap/v1/transactions';

        try {
            $resp = Http::withBasicAuth($serverKey, '')
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $params);
            $status = $resp->status();
            $json   = $resp->json();
            $raw    = $json ?: $resp->body();
            if ($resp->successful()) {
                $token = $json['token'] ?? null;
                $redirectUrl = $json['redirect_url'] ?? null;

                if (!$redirectUrl && $token) {
                    $vtweb = $isProd
                        ? 'https://app.midtrans.com/snap/v2/vtweb/'
                        : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';
                    $redirectUrl = $vtweb . $token;
                }

                return [
                    'success'     => true,
                    'http_status' => $status,
                    'endpoint'    => $endpoint,
                    'token'       => $token,
                    'redirect_url' => $redirectUrl,
                    'message'     => $json['status_message'] ?? 'Successful creation of Snap token.',
                    'raw'         => $raw,
                ];
            }
            return [
                'success'     => false,
                'http_status' => $status,
                'endpoint'    => $endpoint,
                'token'       => null,
                'redirect_url' => null,
                'message'     => $json['error_messages'][0]
                    ?? $json['status_message']
                    ?? $json['message']
                    ?? 'Failed to create Snap token.',
                'raw'         => $raw,
            ];
        } catch (\Throwable $e) {
            return [
                'success'     => false,
                'http_status' => 0,
                'endpoint'    => $endpoint,
                'token'       => null,
                'redirect_url' => null,
                'message'     => $e->getMessage(),
                'raw'         => null,
            ];
        }
    }

    public static function GetSnapToken(array $params): array
    {
        $isProd    = (bool) config('midtrans.is_production', false);
        $baseUrl   = $isProd ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
        $endpoint  = $baseUrl . '/snap/v1/transactions';
        $serverKey = config('midtrans.server_key');
        try {
            $resp = Http::withBasicAuth($serverKey, '')
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $params);

            $status = $resp->status();
            $json   = $resp->json();
            $raw    = $json ?: $resp->body();
            if ($resp->successful()) {
                $token = $json['token'] ?? null;
                $redirectUrl = $json['redirect_url'] ?? null;
                if (!$redirectUrl && $token) {
                    $vtweb = $isProd
                        ? 'https://app.midtrans.com/snap/v2/vtweb/'
                        : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';
                    $redirectUrl = $vtweb . $token;
                }

                return [
                    'success'      => true,
                    'http_status'  => $status, // 201/200
                    'endpoint'     => $endpoint,
                    'token'        => $token,
                    'redirect_url' => $redirectUrl,
                    'message'      => $json['status_message'] ?? 'Successful creation of Snap token.',
                    'raw'          => $raw,
                ];
            }

            return [
                'success'      => false,
                'http_status'  => $status,
                'endpoint'     => $endpoint,
                'token'        => null,
                'redirect_url' => null,
                'message'      => $json['error_messages'][0]
                    ?? $json['status_message']
                    ?? $json['message']
                    ?? 'Failed to create Snap token.',
                'raw'          => $raw,
            ];
        } catch (\Throwable $e) {
            return [
                'success'      => false,
                'http_status'  => 0,
                'endpoint'     => $endpoint,
                'token'        => null,
                'redirect_url' => null,
                'message'      => $e->getMessage(),
                'raw'          => null,
            ];
        }
    }
}
