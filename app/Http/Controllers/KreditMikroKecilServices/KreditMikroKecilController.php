<?php

namespace App\Http\Controllers\KreditMikroKecilServices;

use App\Exports\KreditMikroKecilExport;
use App\Helpers\AesHelper;
use App\Http\Controllers\Controller;
use App\Models\AjpDebiturInvoiceHeader;
use App\Models\PenjaminanTransaction;
use Illuminate\Http\Request;
use App\Services\KreditMikroKecilServices\KreditMikroKecil as KreditMikroKecilServices;
use App\Services\PenjaminanService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class KreditMikroKecilController extends Controller
{
    protected $service;

    public function __construct(KreditMikroKecilServices $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();

            $this->service->processStore($request, $user);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disubmit',
            ]);
        } catch (Exception $ex) {

            $code = $ex->getCode() ?: 500;

            return response()->json([
                'success' => false,
                'message' => $code == 422
                    ? json_decode($ex->getMessage(), true) ?? $ex->getMessage()
                    : $ex->getMessage()
            ], $code);
        }
    }

    public function ApprovePenjaminanKMK(Request $request)
    {
        $trx_no = $request->trxNo;
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approvePenjaminanKMK(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );
            return response()->json([
                'success' => true,
                'message' => 'Penjaminan Multiguna successfully approved.'
            ]);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error while approving Penjaminan Multiguna (' . $ex->getMessage() . ')'
            ], 500);
        }
    }

    public function DownloadTemplateKMK()
    {
        try {
            $filename = 'kredit_mikro_kecil' . date('Y-m-d_H-i-s') . '.xlsx';
            return Excel::download(new KreditMikroKecilExport(), $filename);
        } catch (\Exception $e) {
            Log::error("", ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Error generating Excel file: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        try {
            $user = auth('sanctum')->user();

            $this->service->processUpdateDraft($request, $trxNo, $user);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diupdate',
            ]);
        } catch (\Exception $ex) {

            $code = $ex->getCode() ?: 500;

            return response()->json([
                'success' => false,
                'message' => 'Error While Updating Multiguna: ' . $ex->getMessage()
            ], $code);
        }
    }

    public function GetDetailPaymentKMK(Request $request)
    {
        try {

            $result = $this->service->processGetDetailPaymentKMK($request);

            return response()->json($result);
        } catch (\Exception $ex) {

            $code = $ex->getCode() ?: 500;

            return response()->json([
                'message' => $ex->getMessage()
            ], $code);
        }
    }

    public function GetDetailListPaymentKMK(Request $request)
    {
        try {

            $result = $this->service->processGetDetailListPaymentKMK($request);

            return response()->json($result);
        } catch (\Exception $ex) {

            $code = $ex->getCode() ?: 500;

            return response()->json([
                'message' => $ex->getMessage()
            ], $code);
        }
    }

    public function UploadPembayaranManualKMK(Request $request)
    {
        $request->validate([
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
        ]);

        return $this->service->processUploadPembayaranManualKMK($request);
    }
}
