<?php

namespace App\Http\Controllers\CustomBondServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Http\Controllers\Controller;
use App\Services\CustomBondServices\CustomBond as CustomBondServicesCustomBondTransactionService;
use App\Services\PenjaminanService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomBondTransactionController extends Controller
{
    public function __construct(protected CustomBondServicesCustomBondTransactionService $customBondService) {}
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
            $payload = $validated;
            $data = $this->customBondService->getDetail($payload['trx_no'], $payload['no_surat_permohonan']);
            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Get Data Custom Bond:  ' . $ex->getMessage(), 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $status = strtolower($request->input('data.status'));
            $rules = [
                'product'  => ['nullable', 'string'],
                'data.jenisBond' => 'required|string|max:8',
                'attachments' => 'nullable|array',
                'attachments.*.lampiran_id' => 'required|string',
                'attachments.*.file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:2048',
            ];

            if ($status === 'submit') {
                $rules = array_merge($rules, [
                    'data.noSuratPermohonan' => 'required|string|max:50',
                    'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                    'data.jenisPernyataan' => 'required|string|max:50',
                    'data.skemaPenalty' => 'required|string|max:50',
                    'data.sektor' => 'required|string|max:50',
                    'data.namaPrincipal' => 'required|string|max:255',
                    'data.namaObligee' => 'required|string|max:255',
                    'data.isBast' => 'required|boolean',
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
                    'data.tarif' => 'nullable|numeric|min:0',
                ]);
            } else {
                $rules = array_merge($rules, [
                    'data.noSuratPermohonan' => 'nullable|string|max:50',
                    'data.tglSuratPermohonan' => 'nullable|date_format:Y-m-d',
                    'data.jenisPernyataan' => 'nullable|string|max:50',
                    'data.skemaPenalty' => 'nullable|string|max:50',
                    'data.sektor' => 'nullable|string|max:50',
                    'data.namaPrincipal' => 'nullable|string|max:255',
                    'data.namaObligee' => 'nullable|string|max:255',
                    'data.isBast' => 'nullable|boolean',
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
                    'data.tarif' => 'nullable|numeric|min:0',
                ]);
            }

            $messages = [
                // DATA
                'data.jenisBond.required' => 'Jenis Bond wajib diisi',
                'data.jenisBond.max' => 'Jenis Bond maksimal 8 karakter',
                'data.noSuratPermohonan.required' => 'Nomor Surat Permohonan wajib diisi',
                'data.noSuratPermohonan.max' => 'Nomor Surat Permohonan maksimal 50 karakter',
                'data.tglSuratPermohonan.required' => 'Tanggal Surat Permohonan wajib diisi',
                'data.tglSuratPermohonan.date_format' => 'Format tanggal harus YYYY-MM-DD',
                'data.jenisPernyataan.required' => 'Jenis Pernyataan wajib diisi',
                'data.skemaPenalty.required' => 'Skema penalty wajib diisi',
                'data.sektor.required' => 'Sektor wajib diisi',
                'data.namaPrincipal.required' => 'Nama Principal wajib diisi',
                'data.namaObligee.required' => 'Nama Obligee wajib diisi',
                'data.isBast.required' => 'Field BAST wajib diisi',
                'data.isBast.boolean' => 'Field BAST harus boolean (1/0)',
                'data.namaProyek.required' => 'Nama proyek wajib diisi',
                'data.nilaiProyek.required' => 'Nilai proyek wajib diisi',
                'data.nilaiProyek.numeric' => 'Nilai proyek harus angka',
                'data.nilaiBond.required' => 'Nilai bond wajib diisi',
                'data.nilaiBond.numeric' => 'Nilai bond harus angka',
                'data.nilaiBondPersentase.required' => 'Persentase bond wajib diisi',
                'data.nilaiBondPersentase.numeric' => 'Persentase bond harus angka',
                'data.periodeAwalBerlaku.required' => 'Periode awal wajib diisi',
                'data.periodeAwalBerlaku.date_format' => 'Format tanggal awal harus YYYY-MM-DD',
                'data.periodeAkhirBerlaku.required' => 'Periode akhir wajib diisi',
                'data.periodeAkhirBerlaku.date_format' => 'Format tanggal akhir harus YYYY-MM-DD',
                'data.jangkaWaktu.required' => 'Jangka waktu wajib diisi',
                'data.jangkaWaktu.numeric' => 'Jangka waktu harus angka',
                'data.propinsi.required' => 'Propinsi wajib diisi',
                'data.jenisSuratPerjanjian.required' => 'Jenis surat perjanjian wajib diisi',
                'data.noSuratPerjanjian.required' => 'Nomor surat perjanjian wajib diisi',
                'data.tglSuratPerjanjian.required' => 'Tanggal surat perjanjian wajib diisi',
                'data.tglSuratPerjanjian.date_format' => 'Format tanggal perjanjian harus YYYY-MM-DD',
                'data.tarif.numeric' => 'Tarif harus angka',

                // ATTACHMENTS
                'attachments.array' => 'Attachments harus berupa array',
                'attachments.*.lampiran_id.required' => 'Lampiran ID wajib diisi',
                'attachments.*.file.required' => 'File lampiran wajib diupload',
                'attachments.*.file.mimes' => 'File harus PDF/JPG/JPEG/PNG',
                'attachments.*.file.max' => 'Ukuran file maksimal 2MB',
            ];

            $validator = Validator::make($request->all(), $rules, $messages);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $payload = $validator->validated();

            // DEBUG
            dd($payload);

            $result = $this->customBondService->store($payload, $user);

            return ApiResponse::success($result);
        } catch (ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }

    public function UpdateDraft(Request $request, string $trxNo)
    {
        $user = AuthUserHelper::getUser($request);
        try {
            $validated = $request->validate(
                [
                    'data.trx_status' => 'required|string|in:D,NA',
                    'data.noSuratPermohonan' => 'required|string|max:50',
                    'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                    'data.jenisPernyataan' => 'nullable|string|max:50',
                    'data.skemaPenalty' => 'nullable|string|max:50',
                    'data.sektor' => 'nullable|string|max:50',
                    'data.namaPrincipal' => 'nullable|string|max:255',
                    'data.namaObligee' => 'nullable|string|max:255',
                    'data.isBast' => 'nullable|boolean',
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

                    'data.attachments' => 'nullable|array',
                    'data.attachments.*.file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'data.attachments.*.lampiran_id' => 'required|string',
                    'data.tarif' => 'nullable|numeric|min:0',
                    'data.institutionData' => 'nullable|array',
                ],
                [
                    'data.trx_status.required' => 'trx_status is required',
                    'data.trx_status.in' => 'trx_status must be D or NA',
                    'data.noSuratPermohonan.required' => 'No Surat Permohonan is required',
                    'data.tglSuratPermohonan.required' => 'Tanggal Surat Permohonan is required',
                ]
            );
            $payload = $validated;
            $result = $this->customBondService->updateDraft($payload, $trxNo, $user);
            return ApiResponse::success($result);
        } catch (ValidationException $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $ex->errors()
            ], 422);
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminanCSTB(Request $request)
    {
        $user = AuthUserHelper::getUser($request);
        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approveCSTBPenjaminan($trx_no, $user->user_id, $user->name,  "Perorangan");
            return response()->json([
                'success' => true,
                'message' => 'Penjaminan Custom Bond successfully approved.'
            ]);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error while approving Penjaminan Custom Bond (' . $ex->getMessage() . ')'
            ], 500);
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

            $result = $this->customBondService->processUploadPembayaranManual($payload);

            return response()->json([
                'success' => true,
                'message' => $result
            ]);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function submitDraft(Request $request, string $trxNo)
    {
        try {
            $validated = $request->validate(
                [
                    'data.noSuratPermohonan' => 'required|string|max:50',
                    'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                    'data.jenisBond' => 'required|string|max:8',
                    'data.jenisPernyataan' => 'required|string|max:50',
                    'data.skemaPenalty' => 'required|string|max:50',
                    'data.sektor' => 'required|string|max:50',
                    'data.namaPrincipal' => 'required|string|max:255',
                    'data.namaObligee' => 'required|string|max:255',
                    'data.isBast' => 'required|boolean',
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
                    'data.lampiranEdit' => 'nullable|array',
                    'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'data.lampiranEdit.*.lampiran_id' => 'required_with:data.lampiranEdit|string',
                    'data.tarif' => 'nullable|numeric|min:0'
                ]
            );
            $validated = $validated;
            $payload = $validated['data'];
            // dd($payload);
            $result = $this->customBondService->processSubmitDraft($payload, $trxNo);
            if ($result) {
                return ApiResponse::success('Penjaminan Custom Bond successfully submitted.');
            }
        } catch (ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?: 500
            );
        }
    }

    public function GetDetailPaymentCstb(Request $request)
    {
        try {
            $validated = $request->validate([
                'no_surat_permohonan' => 'required|string|max:100',
                'trx_no' => 'required|string|max:100',
                'is_split' => 'nullable|integer|in:0,1'
            ]);
            $payload = $validated;
            $payload['is_split'] = array_key_exists('is_split', $payload)
                ? (int) $payload['is_split']
                : null;
            $payload['key'] = base64_decode(config('services.secure.key'));
            $result = $this->customBondService->getDetailPaymentCstb($payload);
            return ApiResponse::success($result, 'Success get detail payment');
        } catch (ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (NotFoundException $nfe) {
            return ApiResponse::error($nfe->getMessage(), $nfe->getStatus(), $nfe->getMessageData());
        } catch (Exception $e) {
            Log::error("Error fetching payment details", [
                'exception' => $e,
                'trx_no' => $request->query('trx_no'),
                'no_surat_permohonan' => $request->query('no_surat_permohonan')
            ]);

            return ApiResponse::error(
                $e->getMessage(),
                $e->getCode() ?: 500
            );
        }
    }
}
