<?php

namespace App\Http\Controllers\KbgServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ApiResponse;
use App\Helpers\AuthUserHelper;
use App\Http\Controllers\Controller;
use App\Services\KBGServices\KontraBankGaransiService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KBGTransactionController extends Controller
{
    private $kbgService;
    public function __construct(KontraBankGaransiService $service) {
        $this->kbgService = $service;
    }

    public function store(Request $request)
    {
        try {
            // $userRaw = $request->attributes->get('auth_user');
            // unset($userRaw['user']);
            // $user = (object) collect($userRaw)->all();
            // $user = auth('sanctum')->user();
            $user = AuthUserHelper::getUser($request);
            // dd($user);
            $this->validate($request, [
                'data.institution_data.full_name' => 'required|string|max:64',
                'data.status' => 'required|string|in:draft,submit',
                'data.jenisGaransi' => 'required|string|max:70'
            ]);
            if (strtolower($request->data['status']) == 'submit') {
                $this->validate($request, [
                    'data.noSuratPermohonan' => 'required|string|max:50',
                    'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                    'data.isSplit' => 'required|boolean',
                    'data.jenisPersyaratan' => 'required|string|max:50',
                    'data.skemaPenalty' => 'required|string|max:50',
                    'data.sektor' => 'required|string|max:50',
                    'data.namaPrincipal' => 'required|string|max:255',
                    'data.namaObligee' => 'required|string|max:255',
                    'data.namaBank' => 'required|string|max:50',
                    'data.bankCabang' => 'required|string|max:50',
                    'data.isBast' => 'required|boolean',
                    'data.namaProyek' => 'required|string|max:100',
                    'data.nilaiProyek' => 'required|numeric|min:0',
                    'data.nilaiGaransi' => 'required|numeric|min:0',
                    'data.nilaiGaransiPersentase' => 'required|numeric|min:0',
                    'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
                    'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
                    'data.jangkaWaktu' => 'required|numeric|min:0',
                    'data.provinsi' => 'required|string|max:50',
                    'data.jenisSuratPerjanjian' => 'required|string|max:64',
                    'data.noSuratPerjanjian' => 'required|string|max:64',
                    'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
                    'data.lampiran' => 'required|array|min:1',
                    'data.lampiran.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'data.lampiran.*.lampiran_id' => 'required|string',
                    'data.nilaiAgunan' => 'required|numeric|min:0'
                ]);
            } else {
                $this->validate($request, [
                    'data.noSuratPermohonan' => 'nullable|string|max:50',
                    'data.tglSuratPermohonan' => 'nullable|date_format:Y-m-d',
                    'data.isSplit' => 'nullable|boolean',
                    'data.jenisPersyaratan' => 'nullable|string|max:50',
                    'data.skemaPenalty' => 'nullable|string|max:50',
                    'data.sektor' => 'nullable|string|max:50',
                    'data.namaPrincipal' => 'nullable|string|max:255',
                    'data.namaObligee' => 'nullable|string|max:255',
                    'data.namaBank' => 'nullable|string|max:50',
                    'data.bankCabang' => 'nullable|string|max:50',
                    'data.isBast' => 'nullable|boolean',
                    'data.namaProyek' => 'nullable|string|max:100',
                    'data.nilaiProyek' => 'nullable|numeric|min:0',
                    'data.nilaiGaransi' => 'nullable|numeric|min:0',
                    'data.nilaiGaransiPersentase' => 'nullable|numeric|min:0',
                    'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
                    'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
                    'data.jangkaWaktu' => 'nullable|numeric|min:0',
                    'data.provinsi' => 'nullable|string|max:50',
                    'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
                    'data.noSuratPerjanjian' => 'nullable|string|max:64',
                    'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
                    'data.lampiran' => 'nullable|array|min:1',
                    'data.lampiran.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                    'data.lampiran.*.lampiran_id' => 'required|string',
                    'data.nilaiAgunan' => 'nullable|numeric|min:0'
                ]);
            }
            $isBastPenjaminan = isset($request->data['isBast']) && $request->data['isBast'] == true;
            if ($isBastPenjaminan) {
                $this->validate($request, [
                    'data.noSuratBast' => 'required|string|max:50',
                    'data.tglSuratBast' => 'required|date'
                ]);
            }
            $this->kbgService->kbgStore($request, $user);
            return ApiResponse::success(null, 'Successfully created Penjaminan Kontra Bank Garansi.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            // dd($ex->getMessage());
            return ApiResponse::error(
                $ex->getMessage()
                // $ex->getCode() ?? 500
            );
        }
    }

    public function show(Request $request, string $trx_no)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $result = $this->kbgService->getDetailPenjaminanKbg($trx_no, $user);
            if(!$result['success']) {
                return ApiResponse::error($result['message'], 422);
            }
            return ApiResponse::success($result['data'], 'Successfully get detail Penjaminan Kontra Bank Garansi.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            // dd($ex->getMessage());
            return ApiResponse::error(
                $ex->getMessage()
                // $ex->getCode() ?? 500
            );
        }
    }

    public function updateKbg(Request $request, string $trx_no)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $this->validate($request, [
                'data.jenisGaransi' => 'required|string|max:8',
                'data.jenisGaransiDescription' => 'required|string|max:50',
                'data.noSuratPermohonan' => 'required|string|max:50',
                'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                'data.isSplit' => 'nullable|boolean',
                'data.jenisPersyaratan' => 'nullable|string|max:50',
                'data.skemaPenalty' => 'nullable|string|max:50',
                'data.sektor' => 'nullable|string|max:50',
                'data.namaPrincipal' => 'nullable|string|max:255',
                'data.namaObligee' => 'nullable|string|max:255',
                'data.namaBank' => 'nullable|string|max:50',
                'data.bankCabang' => 'nullable|string|max:50',
                'data.isBast' => 'nullable|boolean',
                'data.namaProyek' => 'nullable|string|max:100',
                'data.nilaiProyek' => 'nullable|numeric|min:0',
                'data.nilaiGaransi' => 'nullable|numeric|min:0',
                'data.nilaiGaransiPersentase' => 'nullable|numeric|min:0',
                'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
                'data.jangkaWaktu' => 'nullable|numeric|min:0',
                'data.provinsi' => 'nullable|string|max:50',
                'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
                'data.noSuratPerjanjian' => 'nullable|string|max:64',
                'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
                'data.lampiranEdit' => 'nullable|array',
                'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiranEdit.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'required|numeric|min:0'
            ]);
            $isBastPenjaminan = isset($request->data['isBast']) && $request->data['isBast'] == true;
            if ($isBastPenjaminan) {
                $this->validate($request, [
                    'data.noSuratBast' => 'required|string|max:50',
                    'data.tglSuratBast' => 'required|date'
                ]);
            }
            $result = $this->kbgService->kbgDraftUpdate($trx_no, $request, $user);
            if(!$result['success'])
            {
                return ApiResponse::error($result['message'], $result['code'] ?? 500);
            }
            return ApiResponse::success(null, 'Successfully updated draft Penjaminan Kontra Bank Garansi.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage()
            );
        }
    }

    public function submitDraft(Request $request, string $trx_no)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $this->validate($request, [
                'data.jenisGaransi' => 'required|string|max:8',
                'data.jenisGaransiDescription' => 'required|string|max:50',
                'data.noSuratPermohonan' => 'required|string|max:50',
                'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                'data.isSplit' => 'required|boolean',
                'data.jenisPersyaratan' => 'required|string|max:50',
                'data.skemaPenalty' => 'required|string|max:50',
                'data.sektor' => 'required|string|max:50',
                'data.namaPrincipal' => 'required|string|max:255',
                'data.namaObligee' => 'required|string|max:255',
                'data.namaBank' => 'required|string|max:50',
                'data.bankCabang' => 'required|string|max:50',
                'data.isBast' => 'required|boolean',
                'data.namaProyek' => 'required|string|max:100',
                'data.nilaiProyek' => 'required|numeric|min:0',
                'data.nilaiGaransi' => 'required|numeric|min:0',
                'data.nilaiGaransiPersentase' => 'required|numeric|min:0',
                'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
                'data.jangkaWaktu' => 'required|numeric|min:0',
                'data.provinsi' => 'required|string|max:50',
                'data.jenisSuratPerjanjian' => 'required|string|max:64',
                'data.noSuratPerjanjian' => 'required|string|max:64',
                'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
                'data.lampiranEdit' => 'required|array',
                'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiranEdit.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'required|numeric|min:0'
            ]);
            $isBastPenjaminan = isset($request->data['isBast']) && $request->data['isBast'] == true;
            if ($isBastPenjaminan) {
                $this->validate($request, [
                    'data.noSuratBast' => 'required|string|max:50',
                    'data.tglSuratBast' => 'required|date'
                ]);
            }
            $result = $this->kbgService->kbgSubmitDraft($trx_no, $request, $user);
            if(!$result['success'])
            {
                return ApiResponse::error($result['message'], $result['code'] ?? 500);
            }
            return ApiResponse::success(null, 'Successfully submitted Penjaminan Kontra Bank Garansi.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage()
            );
        }
    }

    public function uploadPembayaranManual(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $this->validate($request, [
                'trx_no' => 'required|string|max:50',
                'amount' => 'required|numeric',
                'selected_items' => 'required|string',
                'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
            ]);
            $result = $this->kbgService->pembayaranManualKbg($request, $user);
            if(!$result['success'])
            {
                return ApiResponse::error($result['message'], 422);
            }
            return ApiResponse::success(null, 'Successfully uploaded Bukti bayar manual.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage()
            );
        }
    }

    public function approvePenjaminanKBG(Request $request)
    {
        try {
            $user = AuthUserHelper::getUser($request);
            $this->kbgService->approveKBG($request, $user);
            return ApiResponse::success(null, 'Successfully approved Penjaminan Kontra Bank Garansi.');
        } catch (NotFoundException $nfe) {
            return ApiResponse::error(
                $nfe->getMessage(),
                $nfe->getStatus()
            );
        } catch (ValidationException $ve) {
            return ApiResponse::error(
                'Validation error',
                422,
                $ve->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage()
            );
        }
    }
}
