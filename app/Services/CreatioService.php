<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CreatioService
{
    protected $baseUrl;
    protected $username;
    protected $password;
    protected $bpmcsrf;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.creatio.url'), '/');
        $this->username = config('services.creatio.username');
        $this->password = config('services.creatio.password');
    }

    protected function login()
    {
        $url = $this->baseUrl . '/ServiceModel/AuthService.svc/Login';
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($url, [
                'UserName' => $this->username,
                'UserPassword' => $this->password
            ]);
        
        if ($response->successful()) {
            $csrf = $response->header('BPMCSRF');

            // Simpan cookie & csrf ke cache
            $cookies = collect($response->headers()['set-cookie'] ?? [])
                ->map(function ($cookie) {
                    return explode(';', $cookie)[0]; // ambil nama=nilai saja
                })
                ->implode('; ');

            $cookie_1 = $response->cookies()->getCookieByName('.ASPXAUTH')->getName() . '=' . $response->cookies()->getCookieByName('.ASPXAUTH')->getValue() . '; ';
            $cookie_2 = $response->cookies()->getCookieByName('BPMCSRF')->getName() . '=' . $response->cookies()->getCookieByName('BPMCSRF')->getValue() . '; ';
            $cookie_3 = $response->cookies()->getCookieByName('BPMLOADER')->getName() . '=' . $response->cookies()->getCookieByName('BPMLOADER')->getValue() . '; ';
            $cookie_4 = $response->cookies()->getCookieByName('UserType')->getName() . '=' . $response->cookies()->getCookieByName('UserType')->getValue() . '; ';
            $this->bpmcsrf = $response->cookies()->getCookieByName('BPMCSRF')->getValue();
                
            $cookie_str = $cookie_1 . $cookie_2 . $cookie_3 . $cookie_4;
            
            Cache::delete('creatio_cookie_arr');
            Cache::delete('creatio_cookie');

            Cache::put('creatio_cookie_arr', $response->headers()['set-cookie'], now()->addMinutes(20));
            Cache::put('creatio_cookie', $cookie_str, now()->addMinutes(20));

            // Pastikan nilainya string
            if (is_array($csrf)) {
                $csrf = $csrf[0]; // ambil elemen pertama kalau array
            }

            Cache::put('creatio_csrf', $csrf, now()->addMinutes(20));

            return true;
        }

        return false;
    }

    protected function getAuthHeaders()
    {
        // if (!Cache::has('creatio_cookie') || !Cache::has('creatio_csrf')) {
        //     $this->login();
        // }

        $this->login();

        $cookies = Cache::get('creatio_cookie');
        $csrf = Cache::get('creatio_csrf');
        
        if (is_array($csrf)) {
            $csrf = $csrf[0];
        }

        return [
            'Content-Type' => 'application/json',
            'BPMCSRF' => $this->bpmcsrf,
            'Cookie' => $cookies
        ];
    }

    public function request($method, $endpoint, $body = [], $query = [], $retry = 1, $binaryData = '', $binaryType = '')
    {
        $headers = $this->getAuthHeaders();
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        
        $request = Http::withHeaders($headers);
        $tempPath = storage_path('App/lampiran/tempfile.bin');
        
        // Tambahkan metode sesuai kebutuhan
        $response = match (strtolower($method)) {
            'get' => $request->get($url, $query),
            'post' => $request->post($url, $body),
            'put' => $request->put($url, $body),
            'delete' => $request->delete($url),
            'binary' => $request->withBody($binaryData, $binaryType)->post($url),
            'download' => $request->withOptions([
                'verify' => false,
                'stream' => true,
                'read_timeout' => 120,
                'connect_timeout' => 30
                ])->sink($tempPath)->get($url),
            default => throw new \Exception('Unsupported HTTP method'),
        };

        // Jika unauthorized, login ulang
        if ($retry < 4) {
            if ($response->status() === 401 || $response->status() === 403) {
                $this->login();
                return $this->request($method, $endpoint, $body, $query, $retry + 1); // retry
            }
        } 
        

        return $response;
    }
}
