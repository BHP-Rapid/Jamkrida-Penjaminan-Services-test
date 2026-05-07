<?php

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\ZipHelper;
use App\Repositories\PenjaminanTransactionRepository;
use Illuminate\Support\Facades\Validator;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PenjaminanTransactionService
{
    //
    public function __construct(
        protected PenjaminanTransactionRepository $repository
    ) {}

    public function getList(array $params, object $user)
    {
        $mitraId = $this->getTenantMitraDataOrFail($user);
        $query = $this->repository->getTransactionList();
        dd($query);
        if (!is_null($mitraId)) {
            $mitraAlias = $mitraId->alias;
            $query->where('tph.mitra_id', $mitraAlias);
        }
        if (!empty($params['filter'])) {
            foreach ($params['filter'] as $filter) {
                if (!isset($filter['id'], $filter['value'])) continue;
                $field = $filter['id'];
                $value = $filter['value'];
                switch ($field) {
                    case 'trx_no':
                        $query->where('tph.trx_no', 'like', "%{$value}%");
                        break;

                    case 'no_surat_permohonan':
                        $query->where('tph.no_surat_permohonan', 'like', "%{$value}%");
                        break;

                    case 'product':
                        $query->where('tph.product', 'like', "%{$value}%");
                        break;

                    case 'trx_status':
                        $query->where('tph.trx_status', 'like', "%{$value}%");
                        break;

                    case 'created_at':
                        if (is_array($value) && count($value) === 2) {
                            $query->whereBetween('tph.tanggal_surat_permohonan', [
                                min($value),
                                max($value)
                            ]);
                        }
                        break;
                }
            }
        }

        // SORTING
        $sortable = [
            'trx_no' => 'tph.trx_no',
            'created_at' => 'tph.created_at',
        ];

        if (!empty($params['sort_column']) && !empty($params['sort'])) {
            if (isset($sortable[$params['sort_column']])) {
                $query->orderBy($sortable[$params['sort_column']], $params['sort']);
            }
        } else {
            $query->orderBy('tph.created_at', 'desc');
        }
        $perPage = (int)($params['show_page'] ?? 10);
        return $query->paginate($perPage);
    }

    public function storeAdditionalDoc(array $payload)
    {
        $method = $payload['_meta']['method'] ?? null;
        $fullUrl = $payload['_meta']['url'] ?? null;
        $auditPayload = $payload;

        try {

            $formFiles = $payload['FormFile'] ?? [];
            $penjaminanNo = $payload['penjaminan_no'] ?? null;
            $noSuratPermohonan = $payload['no_surat_permohonan'] ?? null;
            $product = $payload['product'] ?? null;
            $noSpDetail = $payload['no_sp_detail'] ?? null;
            $listDocs = [];
            $getDataPenjaminanFlow = null;
            $fileData = $formFiles;
            $checkValidData = $this->repository->findValidAdditionalDocTransaction($penjaminanNo, $noSuratPermohonan);
            if ($checkValidData === null) {
                // return response()->json([
                //     'status' => 'error',
                //     'message' => 'Penjaminan No tidak terdaftar'
                // ], 404);
                throw new NotFoundHttpException('Penjaminan No tidak terdaftar');
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
            $requestBody = $payload;
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
            $directories = [];
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


    public function getAdditionalDocProduct(Request $req)
    {
        $validator = Validator::make(
            $req->query(),
            [
                'trx_no'  => ['required', 'string'],
                'product' => ['required', 'string', 'in:mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj'],
            ],
            [
                'trx_no.required'  => 'trx_no is required',
                'product.required' => 'product is required',
                'product.in'       => 'product must be one of: mlt,srtb,cstb,kmk,ku,kur,kpr,kkpbj',
            ]
        );

        // if ($validator->fails()) {
        //     return ApiResponse::validation($validator->errors());
        // }

        $trxNo  = $req->query('trx_no');
        $product = $req->query('product');

        switch ($product) {
            case 'mlt':
                $result = $this->repository->getMltAdditionalDocument($trxNo);
                break;
            case 'srtb':
                $result = $this->repository->getSrtbAdditionalDoc($trxNo);
                break;
            case 'cstb':
                $result = $this->repository->getCstbbAdditionalDocument($trxNo);
                break;
            case 'kmk':
                $result = $this->repository->getKmkAdditionalDocument($trxNo);
                break;
            case 'ku':
                $result = $this->repository->getKreditUsahaAdditionalDocument($trxNo);
                break;
            case 'kur':
                $result = $this->repository->getKreditUsahaRakyatAdditionalDocument($trxNo);
                break;
            case 'kkpbj':
                $result = $this->repository->getKKPBJAdditionalDocument($trxNo);
                break;
            case 'kpr':
                $result = $this->repository->getKPRAdditionalDocument($trxNo);
                break;

            default:
                return ApiResponse::error('Invalid product', 422);
        }

        if (empty($result)) {
            throw new \Exception('Data tidak ditemukan', 404);
        }

        return $result;
    }

    public function getDetailCertificateByID(Request $req)
    {
        $validator = Validator::make($req->all(), [
            'trx_no' => 'required',
            'product' => 'required',
        ]);
        if ($validator->fails()) {
            // return ApiResponse::validation($validator->errors());
        }
        $input = $validator->validated();
        switch ($input['product']) {
            case 'mlt':
                $result = $this->repository->getDetailCertifiedByIDMLT($input['trx_no']);
                break;
            case 'srtb':
                $result = $this->repository->getDetailCertifiedByIDSrtb($input['trx_no']);
            case 'cstb':
                $result = $this->repository->getDetailCertifiedByIDCstb($input['trx_no']);
            case 'kmk':
                $result = $this->repository->getDetailCertifiedByIDKmk($input['trx_no']);
                break;
            case 'ku':
                $result = $this->repository->getDetailCertifiedByIDKU($input['trx_no']);
                break;
            case 'kur':
                $result = $this->repository->getDetailCertifiedByIDKur($input['trx_no']);
                break;
            case 'kkpbj':
                $result = $this->repository->getDetailCertifiedByIDKkpbj($input['trx_no']);
                break;
            case 'kpr':
                $result = $this->repository->getDetailCertifiedByIDKpr($input['trx_no']);
                break;
            default:
                return ApiResponse::error('Invalid product', 422);
        }
        if (empty($result)) {
            throw new \Exception('Data tidak ditemukan', 404);
        }
        return $result;
    }

    private function getTenantMitraDataOrFail(object $user)
    {
        $tenantData = $this->repository->getTenantMitraData($user->mitra_id);
        if (!$tenantData) {
            throw new NotFoundException('Tenant mitra data is not found.');
        }
        return $tenantData;
    }
}
