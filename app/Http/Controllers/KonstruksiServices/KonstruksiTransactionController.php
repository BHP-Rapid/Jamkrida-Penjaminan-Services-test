<?php

namespace App\Http\Controllers\KonstruksiServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TenantMitra;
use App\Services\CreatioService;
use App\Services\KonstruksiServices\Konstruksi;
use Exception;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class KonstruksiTransactionController extends Controller
{
    protected $service;
    use ValidatesRequests;

    public function __construct(Konstruksi $service)
    {
        $this->service = $service;
    }
    public function show(Request $request)
    {
        try {
            $data = $this->service->getDetail(
                $request->query('trx_no'),
                $request->query('no_surat_permohonan')
            );

            if (!$data) {
                // return response()->json([
                //     'success' => false,
                //     'message' => 'Data not found.'
                // ], 404);
                return ApiResponse::error("Data Not Found", 404);
            }

            return ApiResponse::success($data);
            // return response()->json([
            //     'success' => true,
            //     'data' => $data
            // ]);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }
    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
                ->select('mitra_id', 'alias')
                ->first();
            if ($tenantMitraData) {
                $mitraAlias = $tenantMitraData->alias;
            } else {
                return [
                    'success' => false,
                    'message' => 'Tenant mitra data not found.'
                ];
            }
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
                'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
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

            $result = $this->service->store($request->all(), $user, $mitraAlias, $penjaminanPKSData);

            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], $result['code']);
            }

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            return response()->json([
                'message' => $ve->getMessage(),
                'error' => $ve->errors()
            ], 422);
        } catch (\Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Insert Custom Bond: ' . $ex->getMessage()
            ], 500);
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
