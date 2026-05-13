<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FileInternalClient
{

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.file_internal.url'), '/');
    }

    public function upload(UploadedFile $file, string $module, string $subModule, string $actor, ?string $filename = null): array
    {
        $uploadName = $filename ?: $file->getClientOriginalName();

        $response = Http::acceptJson()
            ->attach(
                'file',
                file_get_contents($file->getRealPath()),
                $uploadName
            )
            ->post($this->baseUrl() . '/api/v1/files/upload', [
                'module' => $module,
                'sub_module' => $subModule,
                'actor' => $actor,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to upload file to internal service with status: ' . $response->status());
        }

        $responseData = $response->json();
        $filePath = data_get($responseData, 'data.path')
            ?? data_get($responseData, 'data.file_path')
            ?? data_get($responseData, 'path')
            ?? data_get($responseData, 'file_path')
            ?? data_get($responseData, 'data.url')
            ?? data_get($responseData, 'url');

        if (empty($filePath)) {
            throw new RuntimeException('Failed to upload file to internal service: file path was not returned.');
        }

        return [
            'path' => $filePath,
            'response' => $responseData,
        ];
    }

    public function getTemporaryUrl(string $fileId): array
    {
        $response = Http::acceptJson()
            ->get($this->baseUrl() . '/api/v1/files/' . $fileId . '/temporary-url');

        if (! $response->successful()) {
            throw new RuntimeException('Failed to get temporary URL from file service with status: ' . $response->status());
        }

        $responseData = $response->json();
        $url = data_get($responseData, 'url');

        if (empty($url)) {
            throw new RuntimeException('Failed to get temporary URL from file service: url was not returned.');
        }

        return [
            'url' => $url,
            'response' => $responseData,
        ];
    }
}
