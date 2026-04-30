<?php

namespace App\Http\Controllers\KreditMikroKecilServices;

use App\Exports\KreditMikroKecilExport;
use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AjpDebiturInvoiceHeader;
use App\Models\PenjaminanTransaction;
use Illuminate\Http\Request;
use App\Services\KreditMikroKecilServices\KreditMikroKecil as KreditMikroKecilServices;
use App\Services\PenjaminanService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class KreditMikroKecilController extends Controller
{
    public function __construct(protected KreditMikroKecilServices $kmkService) {}

    public function store(Request $request)
    {

        try {
            $user = auth('sanctum')->user();

            $this->kmkService->processStore($request, $user);

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

            $this->kmkService->processUpdateDraft($request, $trxNo, $user);

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diupdate',
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function GetDetailPaymentKMK(Request $request)
    {
        try {
            $validator = Validator::make($request->query(), [
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = $validator->validated();
            $payload['is_split'] = array_key_exists('is_split', $payload) ? (int) $payload['is_split'] : null;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $result = $this->kmkService->processGetDetailPaymentKMK($payload);
            return ApiResponse::success($result, 'Success get detail payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function GetDetailListPaymentKMK(Request $request)
    {
        try {

            $validator = Validator::make($request->query(), [
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = $validator->validated();
            $payload['is_split'] = array_key_exists('is_split', $payload) ? (int) $payload['is_split'] : null;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $result = $this->kmkService->processGetDetailListPaymentKMK($payload);

            return ApiResponse::success($result, 'Success get detail list payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function UploadPembayaranManualKMK(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'trx_no' => 'required|string|max:100',
                'amount' => 'required|numeric|min:0',
                'selected_items' => 'required|string',
                'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
            ], [
                'trx_no.required' => 'trx_no is required',
                'amount.required' => 'amount is required',
                'file.required' => 'file is required'
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $result = $this->kmkService->processUploadPembayaranManualKMK($request);
            return ApiResponse::success($result, 'Success get detail payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }
}
