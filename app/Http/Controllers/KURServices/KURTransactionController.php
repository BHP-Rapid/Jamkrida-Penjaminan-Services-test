<?php

namespace App\Http\Controllers\KURServices;

use App\Exceptions\NotFoundException;
use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\KURTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Services\CreatioService;
use App\Services\KURServices\KURService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KURTransactionController extends Controller
{
    private $kurService;
    public function __construct(KURService $service)
    {
        $this->kurService = $service;
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            // $userArray = [
            //     'mitra_id' => '145c9591-c7cf-45fe-a8c1-9f620f992d5d',
            //     'user_id' => 'MDR2025001',
            //     'name' => 'Mitra - Bank Mandiri 1'
            // ];
            // $collectUser = collect($userArray)->all();
            // $user = (object) $collectUser;
            // dd($collectObj->name);
            // dd(collect($user));
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
                // 'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.debitur_kur.nomor_identitas_1' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tarifPercentage' => 'nullable|numeric',
            ]);
            $result = $this->kurService->kurStore($request, $user);
            if(!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'list_debitur' => $result['list_debitur'] ?? [],
                    'dataDebitur' => $result['dataDebitur'] ?? []
                ], 422);
            }
            return ApiResponse::success(null, 'Successfully created Penjaminan KUR.');
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

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        // dd("kkuu");
        if (empty($id)) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required.'
            ], 400);
        }
        $trx_no = $id;
        try {
            $penjaminanDetail = $this->kurService->showKURDetail($trx_no);
            return ApiResponse::success($penjaminanDetail, 'Get detail penjaminan successful.');
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Get Data KUR: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        try {
            $user = auth('sanctum')->user();
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
                'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);
            $result = $this->kurService->kurDraftUpdate($request, $user, $trxNo);
            if(!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                    'list_debitur' => $result['list_debitur'] ?? [],
                    'dataDebitur' => $result['dataDebitur'] ?? []
                ], 422);
            }
            return ApiResponse::success(null, 'Successfully updated draft Penjaminan KUR.');
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

    public function approvePenjaminan(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $trx_no = $request->trxNo;
            $this->kurService->kurApproval($trx_no, $user);
            return ApiResponse::success(null, 'Successfully approve Penjaminan KUR.');
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

    public function uploadPembayaranManual(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $this->validate($request, [
                'trx_no' => 'required|string|max:50',
                'amount' => 'required|numeric',
                'selected_items' => 'required|string',
                'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
            ]);
            $result = $this->kurService->pembayaranManualKur($request, $user);
            if(!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 422);
            }
            return ApiResponse::success(null, 'Successfully upload bukti bayar manual KUR.');
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
                'Error upload bukti bayar manual (' . $ex->getMessage() . ')'
                // $ex->getCode() ?? 500
            );
        }
    }

    public function getDetailPaymentKUR(Request $request)
    {
        try {
            $data = [];
            $dataHeader = $this->kurService->getDataHeaderPaymentFull($request);
            $dataUnpaid = $this->kurService->getDataUnpaidPaymentFull($request->query('trx_no'));
            $data = [
                'dataHeader' =>
                [
                    'data_pending' => $dataHeader,
                    'data_unpaid' => $dataUnpaid
                ]
            ];
            return response()->json($data);

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

    public function getDetailSplitPaymentKUR(Request $request)
    {
        try {
            $result = $this->kurService->getSplitPaymentDetail($request);
            return response()->json([
                'data' => $result
            ]);
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
