<?php

namespace App\Http\Controllers\AjpServices;

use App\Exports\BulkAjpTemplateExport;
use App\Helpers\ApiResponse;
use App\Services\CreatioService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Validation\ValidatesRequests;
use App\Http\Controllers\Controller;
use App\Models\AjpDebiturTenorSchedule;
use App\Models\TenantMitra;
use App\Models\v2\TrxDebiturAjpModel;
use App\Services\AnjakPiutangService\AnjakPiutangService;
use App\Services\PenjaminanService;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;


class AjpController extends Controller
{
    use ValidatesRequests;

    public function __construct(protected AnjakPiutangService $anjakpiutangService) {}


    public function downloadAjpTemplate()
    {
        return Excel::download(new BulkAjpTemplateExport(prefillRows: 1000), 'template_bulk_ajp.xlsx');
    }

    public function storeAjp(Request $request)
    {
        try {
            $result = $this->anjakpiutangService->storeAjp($request, auth('sanctum')->user());

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Unknown response',
                'list_debitur' => $result['list_debitur'] ?? null,
            ], $result['status'] ?? 200);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Insert AJP: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        if (empty($id)) {
            return ApiResponse::error('ID is required', 400);
        }

        try {
            $data = $this->anjakpiutangService->getAjpDetailWithAttachments($id);

            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Get Data AJP: ' . $ex->getMessage(), 500);
        }
    }

    public function updateAjp(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();

        try {
            $result = $this->anjakpiutangService->updateAjp($request, $user, $trxNo);

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? 'Unknown response',
                'list_debitur' => $result['list_debitur'] ?? null,
            ], $result['status'] ?? 200);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Update AJP: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminanAJP(Request $request)
    {
        $trx_no = $request->trxNo;

        try {
            (new PenjaminanService())->approvePenjaminanAJP(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );

            return ApiResponse::success(null, 'Penjaminan AJP approved successfully');
        } catch (Exception $ex) {
            return ApiResponse::error('Error while approving Penjaminan AJP (' . $ex->getMessage() . ')', 500);
        }
    }

    public function GetDetailPaymentAjp(Request $request)
    {
        try {
            $result = $this->anjakpiutangService->getDetailPaymentAjp($request);

            if (($result['success'] ?? false) !== true) {
                return ApiResponse::error($result['message'] ?? 'Data tidak ditemukan', $result['status'] ?? 404);
            }

            return ApiResponse::success($result['data'] ?? [], 'Data retrieved successfully');
        } catch (Exception $ex) {
            Log::error("Error fetching AJP payment details", ['exception' => $ex]);
            return ApiResponse::error('Error fetching AJP payment details (' . $ex->getMessage() . ')', 500);
        }
    }

    public function GetDetailListPaymentAjp(Request $request)
    {
        try {
            $result = $this->anjakpiutangService->getDetailListPaymentAjp($request);

            if (($result['success'] ?? false) !== true) {
                return ApiResponse::error($result['message'] ?? 'Data tidak ditemukan', $result['status'] ?? 404);
            }

            return ApiResponse::success($result['data'] ?? [], 'Data retrieved successfully');
        } catch (Exception $ex) {
            Log::error("Error fetching AJP payment detail list", ['exception' => $ex]);

            return ApiResponse::error('Error fetching AJP payment detail list (' . $ex->getMessage() . ')', 500);
        }
    }

    public function uploadPembayaranManual(Request $request)
    {
        try {
            $result = $this->anjakpiutangService->uploadPembayaranManual($request);

            return ApiResponse::success(null, $result['message'] ?? 'Unknown response');
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

    //this is only used for debugging purpose ( creating invoice number ) you can comment it or delete it
    public function createTrxDebitur(Request $request)
    {
        try {
            $id = 23;
            DB::beginTransaction();

            $data = TrxDebiturAjpModel::where('id_multiguna_ajp', $id)
                ->select('id_trx_debitur_ajp')->get();

            foreach ($data as $d) {
                $lastInvoice = AjpDebiturTenorSchedule::whereNotNull('invoice_number')
                    ->orderByDesc('schedule_id') // safer than created_at
                    ->lockForUpdate()
                    ->first();

                if ($lastInvoice) {
                    // Extract number from INV-008
                    $lastNumber = (int) substr($lastInvoice->invoice_number, 4);
                } else {
                    $lastNumber = 0;
                }

                $newNumber = $lastNumber + 1;

                $newInvoiceNumber = 'INV-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
                //sp_split true

                AjpDebiturTenorSchedule::insert([
                    'id_trx_debitur' => $d->id_trx_debitur_ajp,
                    'tenor_sequence' => 1, //0 fullpayment; 1 installment
                    'due_date'       => now()->addMonth(), // >today()
                    'invoice_number' => $newInvoiceNumber, //nullabel?
                    // 'invoice_id'     => null, //null
                    'amount'         => 2000000, //terserah
                    'status'         => 'Pending',
                ]);
            }
            Db::commit();
            return response()->json(["message" => "success"]);
        } catch (Exception $ex) {
            DB::rollBack();
            throw $ex;
        }
    }
}
