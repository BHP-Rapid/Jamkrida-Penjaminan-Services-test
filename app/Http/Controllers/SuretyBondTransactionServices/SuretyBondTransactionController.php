<?php

namespace App\Http\Controllers\SuretyBondTransactionServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Http\Controllers\Controller;
use App\Services\SuretyBondServices\SuretyBond as SuretyBondTransactionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;


class SuretyBondTransactionController extends Controller
{

    public function __construct(protected SuretyBondTransactionService $suretyBondService) {}


    public function show(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $validated = $request->validate([
                'trx_no' => 'required|string|max:100',
                // 'no_surat_permohonan' => 'required|string|max:100'
            ], [
                'trx_no.required' => 'trx_no is required',
                'trx_no.string' => 'trx_no must be a string'
                // 'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                // 'no_surat_permohonan.string' => 'no_surat_permohonan must be a string'
            ]);
            $data = $this->suretyBondService->handleShow($validated, $user);
            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
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

    public function store(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $status = strtolower($request->input('data.status'));
            $baseRules = [
                'data.institution_data.full_name' => 'required|string|max:64',
                'data.status' => 'required|string|in:draft,submit',
                'data.jenisBond' => 'required|string|max:70',
                'data.jenisBondDescription' => 'required|string|max:255',
            ];
            $submitRules = [
                'data.noSuratPermohonan' => 'required|string|max:50',
                'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                'data.isSplit' => 'required|boolean',
                'data.jenisPernyataan' => 'required|string|max:50',
                'data.skemaPenalty' => 'required|string|max:50',
                'data.sektor' => 'required|string|max:50',
                'data.namaPrincipal' => 'required|string|max:255',
                'data.namaObligee' => 'required|string|max:255',
                'data.isBast' => 'nullable|boolean',
                'data.noSuratBast' => 'required_if:data.isBast,true|nullable|string|max:50',
                'data.tglSuratBast' => 'required_if:data.isBast,true|nullable|date',
                'data.namaProyek' => 'required|string|max:100',
                'data.nilaiProyek' => 'required|numeric|min:0',
                'data.nilaiBond' => 'required|numeric|min:0',
                'data.nilaiBondPersentase' => 'required|numeric|min:0',
                'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
                'data.jangkaWaktu' => 'required|numeric|min:0',
                'data.propinsi' => 'required|string|max:50',
                'data.jenisSuratPerjanjian' => 'required|string|max:64',
                'data.noSuratPerjanjian' => 'required|string|max:64',
                'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
                'data.lampiran' => 'required|array|min:1',
                'data.lampiran.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiran.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'required|numeric|min:0',
            ];

            $draftRules = [
                'data.noSuratPermohonan' => 'nullable|string|max:50',
                'data.tglSuratPermohonan' => 'nullable|date_format:Y-m-d',
                'data.isSplit' => 'nullable|boolean',
                'data.jenisPernyataan' => 'nullable|string|max:50',
                'data.skemaPenalty' => 'nullable|string|max:50',
                'data.sektor' => 'nullable|string|max:50',
                'data.namaPrincipal' => 'nullable|string|max:255',
                'data.namaObligee' => 'nullable|string|max:255',
                'data.isBast' => 'nullable|boolean',
                'data.noSuratBast' => 'required_if:data.isBast,true|nullable|string|max:50',
                'data.tglSuratBast' => 'required_if:data.isBast,true|nullable|date',
                'data.namaProyek' => 'nullable|string|max:100',
                'data.nilaiProyek' => 'nullable|numeric|min:0',
                'data.nilaiBond' => 'nullable|numeric|min:0',
                'data.nilaiBondPersentase' => 'nullable|numeric|min:0',
                'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
                'data.jangkaWaktu' => 'nullable|numeric|min:0',
                'data.propinsi' => 'nullable|string|max:50',
                'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
                'data.noSuratPerjanjian' => 'nullable|string|max:64',
                'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
                'data.lampiran' => 'nullable|array',
                'data.lampiran.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiran.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'nullable|numeric|min:0',
            ];

            $rules = $status === 'submit'
                ? array_merge($baseRules, $submitRules)
                : array_merge($baseRules, $draftRules);

            $validated = $request->validate($rules, [
                'data.status.required' => 'status is required',
                'data.status.in' => 'status must be draft or submit',
            ]);

            // $result = $this->suretyBondService->handleStore($validated, $user);
            $result = $this->suretyBondService->handleStore($validated, $user, $request->data['institution_data']);

            return ApiResponse::success($result, 'Data has been successfully created');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), $ex->getCode() ?: 500);
        }
    }

    public function update(Request $request, string $trxNo)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $validated = $request->validate([
                'data.jenisBond' => 'required|string|max:8',
                'data.jenisBondDescription' => 'required|string|max:255',
                'data.noSuratPermohonan' => 'nullable|string|max:50',
                'data.tglSuratPermohonan' => 'nullable|date_format:Y-m-d',
                'data.isSplit' => 'nullable|boolean',
                'data.jenisPernyataan' => 'nullable|string|max:50',
                'data.skemaPenalty' => 'nullable|string|max:50',
                'data.sektor' => 'nullable|string|max:50',
                'data.namaPrincipal' => 'nullable|string|max:255',
                'data.namaObligee' => 'nullable|string|max:255',
                'data.isBast' => 'nullable|boolean',
                'data.noSuratBast' => 'required_if:data.isBast,true|nullable|string|max:50',
                'data.tglSuratBast' => 'required_if:data.isBast,true|nullable|date',
                'data.namaProyek' => 'nullable|string|max:100',
                'data.nilaiProyek' => 'nullable|numeric|min:0',
                'data.nilaiBond' => 'nullable|numeric|min:0',
                'data.nilaiBondPersentase' => 'nullable|numeric|min:0',
                'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
                'data.jangkaWaktu' => 'nullable|numeric|min:0',
                'data.propinsi' => 'nullable|string|max:50',
                'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
                'data.noSuratPerjanjian' => 'nullable|string|max:64',
                'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
                'data.lampiranEdit' => 'nullable|array',
                'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiranEdit.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'nullable|numeric|min:0',
            ]);
            $result = $this->suretyBondService->updateDraft($validated, $trxNo, $user);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }

    public function submitDraft(Request $request, string $trxNo)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $validated = $request->validate([
                'data.noSuratPermohonan' => 'required|string|max:50',
                'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                'data.isSplit' => 'required|boolean',
                'data.jenisBond' => 'required|string|max:8',
                'data.jenisPernyataan' => 'required|string|max:50',
                'data.skemaPenalty' => 'required|string|max:50',
                'data.sektor' => 'required|string|max:50',
                'data.namaPrincipal' => 'required|string|max:255',
                'data.namaObligee' => 'required|string|max:255',
                'data.isBast' => 'required|boolean',
                'data.noSuratBast' => 'required_if:data.isBast,true|nullable|string|max:50',
                'data.tglSuratBast' => 'required_if:data.isBast,true|nullable|date',
                'data.namaProyek' => 'required|string|max:100',
                'data.nilaiProyek' => 'required|numeric|min:0',
                'data.nilaiBond' => 'required|numeric|min:0',
                'data.nilaiBondPersentase' => 'required|numeric|min:0',
                'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
                'data.jangkaWaktu' => 'required|numeric|min:0',
                'data.propinsi' => 'required|string|max:50',
                'data.jenisSuratPerjanjian' => 'required|string|max:64',
                'data.noSuratPerjanjian' => 'required|string|max:64',
                'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
                'data.nilaiAgunan' => 'required|numeric|min:0',
                'data.lampiranEdit' => 'nullable|array',
                'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiranEdit.*.lampiran_id' => 'required|string',
            ]);
            $result = $this->suretyBondService->handleSubmitDraft($validated, $trxNo, $user);
            return ApiResponse::success($result);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }

    public function approvePenjaminanSB(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $validated = $request->validate([
                'trxNo' => 'required|string|max:100'
            ], [
                'trxNo.required' => 'trxNo is required'
            ]);
            $result = $this->suretyBondService->handleApprovePenjaminanSB($validated['trxNo'], $user);
            return ApiResponse::success(null, $result);
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }

    public function getDetailPaymentSrtb(Request $request)
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
            $data = $this->suretyBondService->handleGetDetailPaymentSrtb($payload);
            return ApiResponse::success($data, 'Success get detail payment');
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation error', 422, $e->errors());
        } catch (Exception $ex) {
            return ApiResponse::error($ex->getMessage(), 500);
        }
    }


    public function uploadPembayaranManual(Request $request)
    {
        try {
            $validated = $request->validate([
                'trx_no' => 'required|string|max:100',
                'amount' => 'required|numeric|min:0',
                'selected_items' => 'required|string',
                'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
            ], [
                'trx_no.required' => 'trx_no is required',
                'amount.required' => 'amount is required',
                'file.required' => 'file is required'
            ]);

            $payload = $validated;
            $payload['selected_items'] = json_decode($payload['selected_items'], true);
            $payload['file'] = $request->file('file');
            $message = $this->suretyBondService->handleUploadPembayaranManual($payload);
           return ApiResponse::success($message);
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
}
