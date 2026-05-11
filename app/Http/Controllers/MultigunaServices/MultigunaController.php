<?php

namespace App\Http\Controllers\MultigunaServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Services\CreatioService;
use App\Services\MultigunaService\MultigunaService;
use App\Models\TenantMitra;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Validation\ValidatesRequests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MultigunaController extends Controller
{
    use ValidatesRequests;

    public function __construct(protected MultigunaService $multigunaService) {}

    public function store(Request $request)
    {
        $user = AuthUserHelper::getUser($request);
        $trxStatus = $request->input('data.trx_status');
        if ($trxStatus === 'D') {
            if ($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
                return ApiResponse::error(
                    'Excel tidak boleh diisi jika ingin Save as Draft',
                    400
                );
            }
        }
        try {
            $validated = $request->validate([
                'data.noSuratPermohonan' => 'required|string',
                'data.pks' => 'required|string',
                'data.jenisProduk' => 'required|string',
                'data.bank' => 'required|string',
                'data.tglSuratPermohonan' => 'required|date',
                'data.spSplit' => 'required|string',
                'data.bankCabang' => 'nullable|string',
                'data.feeBasePercentage' => 'nullable|numeric',
                'data.teksPenjaminanSp' => 'nullable|string',
                'data.dataDebitur' => 'nullable|array',
                'data.dataDebitur.*.attachments' => 'nullable|array',
                'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);

            $payload = $validated;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $files = $request->allFiles();

            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);

            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return ApiResponse::error($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
            }

            $result = $this->multigunaService->storeMultiguna(
                $payload,
                $user,
                $penjaminanPKSData,
                // $files
            );

            return ApiResponse::success($result, 'Data berhasil disimpan');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            $status = $ex->getCode() === 422 ? 422 : 500;
            return ApiResponse::error('Error While Storing Multiguna: ' . $ex->getMessage(), $status);
        }
    }

    public function show(string $trx_no)
    {
        try {
            $validated = validator(['trx_no' => $trx_no],
                [
                    'trx_no' => 'required|string|max:100',
                ],
                [
                    'trx_no.required' => 'trx_no is required',
                    'trx_no.string' => 'trx_no must be a string',
                    'trx_no.max' => 'trx_no max 100 characters',
                ]
            )->validate();
            $data = $this->multigunaService->getMultigunaDetailWithAttachments($validated);
            return ApiResponse::success(
                $data,
                'Data retrieved successfully'
            );
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus(),
                $nfe->getMessageData()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                'Error While Get Data Multiguna: ' . $ex->getMessage(),
                500
            );
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        $user = AuthUserHelper::getUser($request);
        try {
            $this->validate($request, [
                'data.noSuratPermohonan' => 'required|string',
                'data.pks' => 'required|string',
                'data.jenisProduk' => 'required|string',
                'data.bank' => 'required|string',
                'data.tglSuratPermohonan' => 'required|date',
                'data.spSplit' => 'required|string',
                'data.bankCabang' => 'nullable|string',
                'data.feeBasePercentage' => 'nullable|numeric',
                'data.teksPenjaminanSp' => 'nullable|string',
                'data.dataDebitur' => 'nullable|array',
                'data.dataDebitur.*.attachments' => 'nullable|array',
                'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);
            $this->multigunaService->updateMultigunaDraft(
                $trxNo,
                $request->input(),
                $user->user_id ?? null,
                $user->name ?? null
            );

            return ApiResponse::success(null, 'Data berhasil diupdate');
        } catch (ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Updating Multiguna: ' . $ex->getMessage(), 500);
        }
    }

    public function getPenjaminanPKS()
    {
        $mitra_id = auth('sanctum')->user()->mitra_id;

        $mitra = TenantMitra::where('mitra_id', $mitra_id)
            ->select('alias', 'is_syariah', 'is_conventional')
            ->first();

        if ($mitra == null) {
            return ApiResponse::error('Mitra not found for the authenticated user', 404);
        }

        try {
            $pksService = new CreatioService();

            $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
                'MitraID' => $mitra
            ]);

            if ($response->status() !== 200) {
                throw new Exception("Failed to get data from Core Creatio API with status: " . $response->status());
            }

            $apiResBody = json_decode($response->body(), true);

            if (($apiResBody['Success'] ?? false) !== true) {
                throw new Exception("Failed to get data from Core Creatio API with message: " . ($apiResBody['Message'] ?? 'Unknown error'));
            }

            if (!isset($apiResBody['Data']) || !is_array($apiResBody['Data'])) {
                $apiResBody['Data'] = [];
            }

            if ((bool) $mitra->is_syariah === true) {
                $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                    return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Syariah';
                }));
            } else if ((bool) $mitra->is_conventional === true) {
                $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                    return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Non-Syariah';
                }));
            }

            return ApiResponse::success($apiResBody['Data'] ?? [], 'PKS data retrieved successfully');
        } catch (Exception $e) {
            Log::error("", ['exception' => $e]);

            return ApiResponse::error('Error while retrieving PKS data: ' . $e->getMessage(), 500);
        }
    }


    public function GetDetailPaymentMultiguna(Request $request)
    {
        try {

            $validated = $request->validate([
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);


            $payload = $validated;
            $payload['is_split'] = array_key_exists('is_split', $payload) ? (int) $payload['is_split'] : null;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $result = $this->multigunaService->processGetDetailPaymentMLT($payload);

            return ApiResponse::success($result, 'Success get detail list payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), $ex->getCode() ?: 500);
        }
    }

    public function GetDetailListPaymentMultiguna(Request $request)
    {
        try {
            $validated = $request->validate([
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);


            $payload = $validated;
            $payload['is_split'] = array_key_exists('is_split', $payload) ? (int) $payload['is_split'] : null;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $result = $this->multigunaService->processGetDetailListPaymentMLT($payload);

            return ApiResponse::success($result, 'Success get detail list payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }
}
