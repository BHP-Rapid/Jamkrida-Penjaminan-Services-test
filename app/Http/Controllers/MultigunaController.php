<?php

namespace App\Http\Controllers;

use App\Helper\ApiResponse;
use App\Services\CreatioService;
use App\Services\MultigunaService\MultigunaService;
use App\Models\TenantMitra;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Validation\ValidatesRequests;


class MultigunaController extends Controller
{
    use ValidatesRequests;

    public function __construct(protected MultigunaService $multigunaService) {}

    public function store(Request $request)
    {
        $user = auth('sanctum')->user();

        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant mitra data not found.'
            ], 404);
        }

        $mitraAlias = $tenantMitraData->alias;

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

            if ($request->data['trx_status'] == 'D') {
                if ($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Excel tidak boleh diisi Jika dalam Ingin Save as Draft'
                    ], 500);
                }
            }

            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);

            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return response()->json([
                    'status' => 'error',
                    'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
                ], 500);
            }

            $this->multigunaService->storeMultiguna($request, $user, $mitraAlias, $penjaminanPKSData);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disubmit',
            ]);
        } catch (Exception $ex) {
            $status = $ex->getCode() === 422 ? 422 : 500;

            return response()->json([
                'success' => false,
                'message' => 'Error While Insert Multiguna: ' . $ex->getMessage()
            ], $status);
        }
    }

    public function show($id)
    {
        if (empty($id)) {
            return ApiResponse::error('ID is required', 400);
        }

        try {
            $data = $this->multigunaService->getMultigunaDetailWithAttachments($id);

            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Get Data Multiguna: ' . $ex->getMessage(), 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();
        try {
            $this->multigunaService->updateMultigunaDraft(
                $trxNo,
                $request->input(),
                $user->user_id ?? null,
                $user->name ?? null
            );

            return ApiResponse::success([], 'Data berhasil diupdate');
        } catch (ModelNotFoundException $ex) {
            return ApiResponse::error('Data tidak ditemukan', 404);
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
            return response()->json([
                'success' => false,
                'message' => 'Mitra not found.'
            ], 404);
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

            return response()->json($apiResBody);
        } catch (Exception $e) {
            Log::error("", ['exception' => $e]);

            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
