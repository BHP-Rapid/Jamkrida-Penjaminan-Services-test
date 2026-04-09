<?php

namespace App\Services;

use App\Helpers\ZipHelper;
use App\Repositories\PenjaminanTransactionRepository;
use Illuminate\Support\Facades\Validator;
use App\Models\PenjaminanTransaction;
use App\Models\PenjaminanLampiranDtl;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;



class PenjaminanTransactionService
{
    //
    public function __construct(
        protected PenjaminanTransactionRepository $repository
    ) {}

    public function getList(array $params)
    {
        $validator = Validator::make($params, [
            'sort' => 'nullable|string|in:asc,desc',
            'sort_column' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'show_page' => 'nullable|integer|min:1',
            'filter' => 'nullable|array',
            'mitra_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
        return $this->repository->getTransactionList($params);
    }

    public function storeAdditionalDoc(Request $req)
    {
        $method = $req->method();
        $fullUrl = $req->fullUrl();
        $auditPayload = $req->all();

        try {
            $req->validate([
                'penjaminan_no' => 'required|string',
                'no_surat_permohonan' => 'required|string',
                'FormFile.*' => 'string',
            ]);
            $formFiles = $req->input('FormFile');
            $penjaminanNo = $req->input('penjaminan_no');
            $noSuratPermohonan = $req->input('no_surat_permohonan');
            $product = $req->input('product');
            $noSpDetail = $req->input('no_sp_detail');
            $listDocs = [];
            $getDataPenjaminanFlow = null;
            $fileData = $formFiles;
            $checkValidData = $this->repository->findValidAdditionalDocTransaction($penjaminanNo, $noSuratPermohonan);
            if ($checkValidData === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Penjaminan No tidak terdaftar'
                ], 404);
            }
            if ($product === 'mlt') {
                $getDataPenjaminanFlow = $this->repository->getMultigunaPenjaminanFlow($penjaminanNo, $noSpDetail);
                $listDocs = [];
                if (!empty($getDataPenjaminanFlow?->additional_document)) {
                    $decoded  = json_decode($getDataPenjaminanFlow->additional_document, true) ?: [];
                    $listDocs = $decoded['AdditionalDoc'] ?? [];
                }
            }

            if (str_starts_with($fileData, 'data:')) {
                $pos = strpos($fileData, 'base64,');
                $cleaned = substr($fileData, $pos + strlen('base64,'));
                $fileData = $cleaned;
            }
            $decodedFile = base64_decode($fileData, true);
            if ($decodedFile === false) {
                return response()->json(['message' => 'Base64 decode gagal'], 422);
            }
            file_put_contents(storage_path('app/additionalDoc.zip'), $decodedFile);
            $unzipResult = ZipHelper::unzipBase64($decodedFile, 'additional');
            $requestBody = $req->all();
            unset($requestBody["FormFile"]);
            $mergedBody = array_merge($requestBody, $unzipResult);
            json_encode($mergedBody);
            if (isset($unzipResult['error'])) {
                return response()->json($unzipResult, 422);
            }
            $tempExtractPath = storage_path('app/uploads/additionalDoc');
            if (is_dir($tempExtractPath)) {
                $files = scandir($tempExtractPath);
                $directories = array_filter($files, function ($file) use ($tempExtractPath) {
                    return is_dir($tempExtractPath . DIRECTORY_SEPARATOR . $file) && $file !== '.' && $file !== '..';
                });
            }
            $namaDocs = array_map(fn($doc) => $doc['NamaDokumen'] ?? null, $listDocs);
            $missingDirsForDocs = array_values(array_diff($namaDocs, $directories));
            $extraDirsNoDocs    = array_values(array_diff($directories, $namaDocs));
            $matched            = array_values(array_intersect($namaDocs, $directories));
            // dd($matched);
            if (count($missingDirsForDocs) !== 0) {
                return response()->json(
                    [
                        'status' => false,
                        'message' => 'Terdapat kekurangan document : ' . $missingDirsForDocs
                    ],
                    401
                );
            }
            if (count($extraDirsNoDocs) !== 0) {
                return response()->json(
                    [
                        'status' => false,
                        'message' => 'Terdapat document yang tidak butuhkan : ' . $extraDirsNoDocs
                    ],
                    401
                );
            }

            $resultsPerFolder = $resultsPerFolder ?? [];
            $pathByProduct = [
                'mlt'  => 'uploads/penjaminan/multiguna',
                'cstb' => 'uploads/penjaminan/custom-bond',
                'srtb' => 'uploads/penjaminan/surety-bond',
                'kmk'  => 'uploads/penjaminan/kmk',
                'ku'   => 'uploads/penjaminan/kredit-usaha',
                'kur'  => 'uploads/penjaminan/kur',
                'kkpbj' => 'uploads/penjaminan/kkpbj',
                'kpr'  => 'uploads/penjaminan/kpr',
            ];
            // foreach ($matched as $docName) {
            //     $svcPenjCreatio = new CreatioService();
            //     $folder = trim((string) $docName);
            //     $folder = Str::of($folder)->replace(['..', '/', '\\'], '')->value();

            //     $absPath = storage_path('app/uploads/additionalDoc' . DIRECTORY_SEPARATOR . $folder);

            //     if (!File::exists($absPath) || !File::isDirectory($absPath)) {
            //         $resultsPerFolder[$folder] = ['error' => 'Folder tidak ditemukan'];
            //         continue;
            //     }

            //     $files = File::files($absPath);

            //     foreach ($files as $file) {
            //         $fileExt  = $file->getExtension();
            //         $filePath = $file->getRealPath();
            //         $mimeType     = File::mimeType($filePath);
            //         $fileContents = File::get($filePath);
            //         $p = strtolower((string) $product);
            //         if (!isset($pathByProduct[$p])) {
            //             throw new Exception("Product tidak dikenal: {$product}");
            //         }
            //         $filenameCreatio = "{$getDataPenjaminanFlow->nomor_permohonan}-{$folder}.{$fileExt}";
            //         if ($getDataPenjaminanFlow->nomor_permohonan == null) {
            //             $fn = "{$getDataPenjaminanFlow->no_surat_permohonan}-{$folder}-{$p}";
            //         } else {
            //             $fn = "{$getDataPenjaminanFlow->nomor_permohonan}-{$folder}-{$p}";
            //         }

            //         $relativePath = $pathByProduct[$p];
            //         $filenameS3   = "{$fn}.{$fileExt}";
            //         Storage::disk('s3')->put($relativePath . '/' . $filenameS3, $fileContents);
            //         $path = $relativePath . '/' . $filenameS3;
            //         // PenjaminanLampiranDtl::create([
            //         //     'trx_no'        => $penjaminanNo,
            //         //     'lampiran_id'   => strtolower((string) $docName),
            //         //     'file_name'     => $filenameS3,
            //         //     'file_info'     => $path,
            //         //     'mime_type'     => $mimeType,
            //         //     'version'       => 1,
            //         //     'status_doc'    => 'N',
            //         //     'is_additional' => 1,
            //         // ]);

            //         $payloadDocument = [];
            //         if (is_null($noSpDetail) && ($p == 'mlt' || $p == 'srtb')) {
            //             $payloadDocument = [
            //                 "NomorPermohonan" => $noSuratPermohonan,
            //                 "NamaDokumen"     => $filenameCreatio,
            //                 "JenisDokumen"    => "Tambahan",
            //                 "TipeDokumen"     => "Dokumen Tambahan",
            //                 "DataBase64"      => base64_encode($fileContents),
            //             ];
            //         } else {
            //             $payloadDocument = [
            //                 "NomorPermohonan" => $getDataPenjaminanFlow->nomor_permohonan,
            //                 "NamaDokumen"     => $filenameCreatio,
            //                 "JenisDokumen"    => "Tambahan",
            //                 "TipeDokumen"     => "Dokumen Tambahan",
            //                 "DataBase64"      => base64_encode($fileContents),
            //             ];
            //         }
            //         // dd($payloadDocument);
            //         $response = $svcPenjCreatio->request(
            //             'post',
            //             '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan',
            //             $payloadDocument
            //         );

            //         if ($response->status() !== 200) {
            //             throw new Exception("Failed to Send Additional Document {$filenameCreatio} to Creatio, status: {$response->status()}");
            //         }

            //         $bodyResponse = json_decode($response->body(), true);
            //         if (($bodyResponse['Success'] ?? false) !== true) {
            //             $msg = $bodyResponse['Message'] ?? 'Unknown error';
            //             throw new Exception("Failed to Send Additional Document {$filenameCreatio} to Creatio, message: {$msg}");
            //         }
            //     }
            // }
            if (File::exists($tempExtractPath) && File::isDirectory($tempExtractPath)) {
                File::deleteDirectory($tempExtractPath);
            }
            // $auditService = $auditTrail->logAuditTrail(
            //     $method,
            //     $fullUrl,
            //     'penjaminan_lampiran_dtl',
            //     auth('sanctum')->user()->email,
            //     auth('sanctum')->user()->role,
            //     json_encode([
            //         'body'    => $mergedBody
            //     ]),
            //     auth('sanctum')->user()->user_id,
            //     auth('sanctum')->user()->name,
            //     true
            // );
            // if (!$auditService) {
            //     throw new Exception("Failed to insert audit trail record.");
            // }
            return response()->json([
                'success' => true,
                'message' => 'Data Additional Document has submitted',
            ], 200);
        } catch (Exception $ex) {
        }
    }
}
