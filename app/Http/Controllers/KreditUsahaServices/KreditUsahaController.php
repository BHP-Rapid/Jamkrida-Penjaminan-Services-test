<?php

namespace App\Http\Controllers\KreditUsahaServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TenantMitra;
use App\Services\CreatioService;
use App\Services\KreditUsahaService\KreditUsaha;
use App\Services\PenjaminanService;
use Exception;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class KreditUsahaController extends Controller
{
    protected $service;
    use ValidatesRequests;
    public function __construct(KreditUsaha $service)
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
                return ApiResponse::error("Data Not Found", 404);
            }

            return ApiResponse::success($data);
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
            $tenant_ID = '';
            $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
                ->select('mitra_id', 'alias')
                ->first();
            if ($tenantMitraData) {
                $mitraAlias = $tenantMitraData->alias;
                $tenant_ID = $tenantMitraData->tenant_id;
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
                if (!empty($request->allFiles())) {
                    return ApiResponse::error('File upload tidak diperbolehkan saat Save as Draft.', 422);
                }
            }

            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return ApiResponse::error($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
            }

            $result = $this->service->store($request->all(), $user, $mitraAlias, $penjaminanPKSData, $tenant_ID);

            if (isset($result['error'])) {
                return ApiResponse::error($result['message'], $result['code']);
            }

            return ApiResponse::success($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (\Exception $ex) {
            return ApiResponse::error(
                'Error While Insert Kredit Usaha: ' . $ex->getMessage(),
                500
            );
        }
    }

    public function updateKreditUsaha(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return ApiResponse::error('Tenant mitra data not found.', 404);
        }

        $mitraAlias = $tenantMitraData->alias;
        try {

            $this->validate($request, [
                'data.noSuratPermohonan'  => 'required|string',
                'data.pks'                => 'required|string',
                'data.jenisProduk'        => 'required|string',
                'data.bank'               => 'required|string',
                'data.tglSuratPermohonan' => 'required|date',
                'data.spSplit'            => 'required|string',
                'data.bankCabang'         => 'nullable|string',
                'data.feeBasePercentage'  => 'nullable|numeric',
                'data.teksPenjaminanSp'   => 'nullable|string',
                'data.dataDebitur'        => 'nullable|array',
                'data.dataInstitution'    => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);

            $newStatus = $request->data['trx_status'];

            // Validasi file
            if ($newStatus === 'D') {
                if (!empty($request->allFiles())) {
                    return ApiResponse::error('File upload tidak diperbolehkan saat Save as Draft.', 422);
                }
            } else {
                if (empty($request->allFiles())) {
                    return ApiResponse::error('File upload wajib diisi saat Submit.', 422);
                }
            }

            // Validasi PKS
            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return ApiResponse::error($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
            }

            $result = $this->service->update($request->all(), $user, $mitraAlias, $penjaminanPKSData, $trxNo, $newStatus);

            return ApiResponse::success($result);
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Updating Kredit Usaha: ' . $ex->getMessage(), 500);
        }
    }

    public function ApprovePenjaminanKreditUsaha(Request $request)
    {

        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approvePenjaminanKreditUsaha(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );

            return ApiResponse::success(null, 'Penjaminan Kredit Usaha successfully approved.');
        } catch (Exception $ex) {
            return ApiResponse::error('Error while approving Penjaminan Kredit Usaha (' . $ex->getMessage() . ')', 500);
        }
    }

    public function GetDetailPaymentKreditUsaha(Request $request)
    {
        try {
            $result = $this->service->GetDetailPaymentKreditUsaha($request);

            if (($result['success'] ?? false) !== true) {
                return ApiResponse::error($result['message'] ?? 'Data tidak ditemukan', $result['status'] ?? 404);
            }

            return ApiResponse::success($result['data'] ?? [], 'Data retrieved successfully');
        } catch (Exception $ex) {
            Log::error("Error fetching Kredit Usaha payment details", ['exception' => $ex]);
            return ApiResponse::error('Error fetching Kredit Usaha payment details (' . $ex->getMessage() . ')', 500);
        }
    }

    public function GetDetailListPaymentKreditUsaha(Request $request)
    {
        try {
            $result = $this->service->GetDetailListPaymentKreditUsaha($request);

            if (($result['success'] ?? false) !== true) {
                return ApiResponse::error($result['message'] ?? 'Data tidak ditemukan', $result['status'] ?? 404);
            }

            return ApiResponse::success($result['data'] ?? [], 'Data retrieved successfully');
        } catch (Exception $ex) {
            Log::error("Error fetching Kredit Usaha payment detail list", ['exception' => $ex]);

            return ApiResponse::error('Error fetching Kredit Usaha payment detail list (' . $ex->getMessage() . ')', 500);
        }
    }

    public function uploadPembayaranManual(Request $request)
    {
        $this->validate($request, [
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            // 'selected_item_old' => 'required|array',
            // 'selected_item_old.*.amount' => 'required|numeric',
            // 'selected_item_old.*.invoice_number' => 'required|string|max:50',
            // 'selected_item_old.*.nik' => 'required|string|max:50'
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
        ]);
        if (
            !json_validate($request->selected_items) ||
            !is_array(json_decode($request->selected_items))
        ) {
            return ApiResponse::error('Invalid selected item data.', 400);
        }

        try {
            $this->service->uploadPembayaranManual($request);

            return ApiResponse::success(null, 'Bukti bayar manual successfully uploaded.');
        } catch (Exception $e) {
            return ApiResponse::error('Error upload bukti bayar manual (' . $e->getMessage() . ')', 500);
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
