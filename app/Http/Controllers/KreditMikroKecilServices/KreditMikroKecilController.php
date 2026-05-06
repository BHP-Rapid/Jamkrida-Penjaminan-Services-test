<?php

namespace App\Http\Controllers\KreditMikroKecilServices;

use App\Exports\KreditMikroKecilExport;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\KreditMikroKecilServices\KreditMikroKecil as KreditMikroKecilServices;
use App\Services\PenjaminanService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class KreditMikroKecilController extends Controller
{
    public function __construct(protected KreditMikroKecilServices $kmkService) {}

    public function store(Request $request)
    {
        try {
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

            $rules = [
                'data.status' => 'required|string|in:draft,submit',
                'data.noSuratPermohonan' => 'nullable|string|required_if:data.status,submit',
                'data.pks' => 'nullable|string|required_if:data.status,submit',
                'data.jenisProduk' => 'nullable|string|required_if:data.status,submit',
                'data.bank' => 'nullable|string|required_if:data.status,submit',
                'data.tglSuratPermohonan' => 'nullable|date|required_if:data.status,submit',
                'data.spSplit' => 'nullable|string|required_if:data.status,submit',
                'data.bankCabang' => 'nullable|string',
                'data.feeBasePercentage' => 'nullable|numeric',
                'data.teksPenjaminanSp' => 'nullable|string',
                'data.dataDebitur' => 'nullable|array',
                'data.dataDebitur.*.attachments' => 'nullable|array',
                'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric|required_if:data.status,submit',
            ];
            $validated = $request->validate($rules, [
                'data.status.required' => 'status is required',
                'data.status.in' => 'status must be draft or submit',
            ]);
            $this->kmkService->processStore($validated, $user);
            return ApiResponse::success(null, 'Data berhasil disimpan');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function ApprovePenjaminanKMK(Request $request)
    {
        $trx_no = $request->trxNo;
        $user = AuthUserHelper::getUser($request);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approvePenjaminanKMK(
                $trx_no,
                $user->user_id,
                $user->name,
                "Perorangan"
            );
            return ApiResponse::success(null, 'Penjaminan Multiguna successfully approved.');
        } catch (Exception $ex) {
            return ApiResponse::error('Error while approving Penjaminan Multiguna (' . $ex->getMessage() . ')', 500);
        }
    }

    public function DownloadTemplateKMK()
    {
        try {
            $filename = 'kredit_mikro_kecil' . date('Y-m-d_H-i-s') . '.xlsx';
            return Excel::download(new KreditMikroKecilExport(), $filename);
        } catch (\Exception $e) {
            Log::error("", ['exception' => $e]);
            return ApiResponse::error('Error generating Excel file: ' . $e->getMessage(), 500);
        }
    }

    public function show(Request $request)
    {
        try {
            $validated = $request->validate([
                'trx_no' => 'required|string|max:100',
                'no_surat_permohonan' => 'required|string|max:100'
            ], [
                'trx_no.required' => 'trx_no is required',
                'trx_no.string' => 'trx_no must be a string',
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'no_surat_permohonan.string' => 'no_surat_permohonan must be a string'
            ]);
            $data = $this->kmkService->handleShow($validated);
            return ApiResponse::success($data, 'Data retrieved successfully');
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

    public function updateDraft(Request $request, string $trxNo)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $this->kmkService->processUpdateDraft($request, $trxNo, $user);
            return ApiResponse::success(null, 'Data berhasil diupdate');
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
            $validator = $request->validate([
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);
            $payload = $validator;
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

            $validator = $request->validate([
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ], [
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'trx_no.required' => 'trx_no is required'
            ]);
            $payload = $validator;
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
            $validator = $request->validate([
                'trx_no' => 'required|string|max:100',
                'amount' => 'required|numeric|min:0',
                'selected_items' => 'required|string',
                'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
            ], [
                'trx_no.required' => 'trx_no is required',
                'amount.required' => 'amount is required',
                'file.required' => 'file is required'
            ]);
            $result = $this->kmkService->processUploadPembayaranManualKMK($validator);
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
