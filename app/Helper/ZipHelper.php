<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ZipHelper
{
    public static function unzipBase64(string $decodedFile, string $module): array
    {
        $isBulk = ($module === 'bulkUpload');
        $timestamp = Carbon::now('Asia/Jakarta')->format('Ymd_His');
        $zipFileName = $isBulk
            ? "lampiran_{$timestamp}.zip"
            : "additionalDoc_{$timestamp}.zip";

        $relativeExtractPath = $isBulk
            ? 'uploads/lampiran'
            : 'uploads/additionalDoc';

        $tempExtractPath = storage_path('app/' . $relativeExtractPath);

        // simpan file zip
        Storage::disk('local')->put($zipFileName, $decodedFile);

        $zipFilePath = storage_path('app/' . $zipFileName);

        // buat folder extract
        if (!file_exists($tempExtractPath)) {
            mkdir($tempExtractPath, 0777, true);
        }

        // cek file ada
        if (!file_exists($zipFilePath)) {
            return [
                'error' => 'File ZIP tidak ditemukan di path: ' . $zipFilePath
            ];
        }

        // validasi mime
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($zipFilePath);

        if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed'])) {
            return [
                'error' => 'File bukan ZIP. MIME type: ' . $mimeType
            ];
        }

        // unzip
        $zip = new ZipArchive();
        $res = $zip->open($zipFilePath);

        if ($res !== true) {
            return [
                'error' => 'Gagal membuka ZIP',
                'zip_error_code' => $res,
                'mime' => $mimeType,
                'path' => $zipFilePath
            ];
        }

        $zip->extractTo($tempExtractPath);
        $zip->close();

        // hapus zip setelah extract
        Storage::disk('local')->delete($zipFileName);

        // ambil list folder
        $listAllFolder = Storage::disk('local')->directories($relativeExtractPath);

        return [
            'fileNameZip' => $zipFileName,
            'listFolder'  => $listAllFolder,
        ];
    }
}