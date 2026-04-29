<?php

namespace App\Http\Controllers\SuretyBondTransactionServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\SuretyBondServices\SuretyBond as SuretyBondTransactionService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuretyBondTransactionController extends Controller
{

    public function __construct(protected SuretyBondTransactionService $suretyBondService) {}


    public function show(Request $request)
    {
        try {
            $validator = Validator::make($request->query(), [
                'trx_no' => 'required|string|max:100',
                'no_surat_permohonan' => 'required|string|max:100'
            ], [
                'trx_no.required' => 'trx_no is required',
                'trx_no.string' => 'trx_no must be a string',
                'no_surat_permohonan.required' => 'no_surat_permohonan is required',
                'no_surat_permohonan.string' => 'no_surat_permohonan must be a string'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payload = $validator->validated();
            $data = $this->suretyBondService->handleShow($payload);
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

    public function store(Request $request)
    {
        try {
            $status = strtolower($request->input('data.status'));

            $baseRules = [
                'data.institution_data.full_name' => 'required|string|max:64',
                'data.status' => 'required|string|in:draft,submit',
                'data.jenisBond' => 'required|string|max:70',
                'data.jenisBondDescription' => 'required|string|max:255',
            ];

            if ($status === 'submit') {
                $validator = Validator::make($request->all(), array_merge($baseRules, [
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
                ]), [
                    'data.status.required' => 'status is required',
                    'data.status.in' => 'status must be draft or submit',
                ]);
            } else {
                $validator = Validator::make(
                    $request->all(),
                    array_merge($baseRules, [
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
                    ]),
                    [
                        'data.status.required' => 'status is required',
                        'data.status.in' => 'status must be draft or submit',
                    ]
                );
            }

            $validator->after(function ($validator) use ($request) {
                $lampiran = $request->input('data.lampiran', []);

                if (!is_array($lampiran) || empty($lampiran)) {
                    return;
                }

                $lampiranIds = array_filter(array_column($lampiran, 'lampiran_id'), fn($id) => $id !== null);

                if (count($lampiranIds) !== count(array_unique($lampiranIds))) {
                    $validator->errors()->add('data.lampiran', 'Duplicate lampiran id.');
                }
            });

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payload = $validator->validated();
            $result = $this->suretyBondService->handleStore($payload);

            return $result;
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

    public function update(Request $request, $trxNo)
    {
        try {
            $validator = Validator::make(['trxNo' => $trxNo], [
                'trxNo' => 'required|string|max:100'
            ], [
                'trxNo.required' => 'trxNo is required'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $message = $this->suretyBondService->handleUpdate($request, $trxNo);

            return response()->json([
                'success' => true,
                'message' => $message
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
                500
            );
        }
    }

    public function submitDraft(Request $request, string $trxNo)
    {
        try {
            $validator = Validator::make(array_merge($request->all(), ['trxNo' => $trxNo]), [
                'trxNo' => 'required|string|max:100',
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
            ], [
                'trxNo.required' => 'trxNo is required'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $message = $this->suretyBondService->handleSubmitDraft($request, $trxNo);

            return response()->json([
                'success' => true,
                'message' => $message
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
                500
            );
        }
    }

    public function approvePenjaminanSB(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'trxNo' => 'required|string|max:100'
            ], [
                'trxNo.required' => 'trxNo is required'
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payload = $validator->validated();
            $message = $this->suretyBondService->handleApprovePenjaminanSB($payload['trxNo']);

            return response()->json([
                'success' => true,
                'message' => $message
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
                500
            );
        }
    }

    public function getDetailPaymentSrtb(Request $request)
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

            $validator->after(function ($validator) use ($request) {
                $selectedItems = $request->input('selected_items');
                $decodedItems = json_decode($selectedItems, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedItems)) {
                    $validator->errors()->add('selected_items', 'selected_items must be a valid JSON array');
                }
            });

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payload = $validator->validated();
            $payload['selected_items'] = json_decode($payload['selected_items'], true);
            $payload['file'] = $request->file('file');
            $message = $this->suretyBondService->handleUploadPembayaranManual($payload);

            return response()->json([
                'success' => true,
                'message' => $message
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
}
