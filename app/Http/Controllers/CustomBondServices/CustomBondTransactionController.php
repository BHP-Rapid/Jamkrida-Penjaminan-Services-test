<?php

namespace App\Http\Controllers\CustomBondServices;

use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\PenjaminanTransaction;
use App\Services\CustomBondServices\CustomBond as CustomBondServicesCustomBondTransactionService;
use App\Services\PenjaminanService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomBondTransactionController extends Controller
{
    protected $service;
    use ValidatesRequests;

    public function __construct(CustomBondServicesCustomBondTransactionService $service)
    {
        $this->service = $service;
    }

    public function show(Request $request)
    {
        try {
            if (empty($id)) {
                return ApiResponse::error('ID is required', 400);
            }
            $data = $this->service->getDetail($request->query('trx_no'), $request->query('no_surat_permohonan'));
            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (\Exception $ex) {
            return ApiResponse::error('Error While Get Data Custom Bond:  ' . $ex->getMessage(), 500);
            // return response()->json([
            //     'success' => false,
            //     'message' => 'Error While Get Data Custom Bond: ' . $ex->getMessage()
            // ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $status = strtolower($request->input('data.status'));
            $rules = [
                'order_id' => ['required', 'string'],
                'trx_no'   => ['required', 'string'],
                'product'  => ['nullable', 'string'],
                'data.jenisBond' => 'required|string|max:8',
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
            $validator = Validator::make(
                $request->all(),
                $rules,
                [
                    'order_id.required' => 'order_id is required',
                    'trx_no.required'   => 'trx_no is required',
                    'data.status.required' => 'status is required',
                    'data.status.in' => 'status must be submit or draft',
                ]
            );
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = $validator->validated();
            $result = $this->service->store($payload, $user);
            return ApiResponse::success($result);
        } catch (\Illuminate\Validation\ValidationException $ex) {
            return ApiResponse::error('Validation error', 422, $ex->errors());
        } catch (\Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                500
            );
        }
    }

    public function UpdateDraft(Request $request, string $trxNo)
    {
        $user = auth('sanctum')->user();
        try {
            $validator = Validator::make(
                $request->all(),
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

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
            $payload = $validator->validated();
            $result = $this->service->updateDraft($payload, $trxNo, $user);
            return ApiResponse::success($result);
        } catch (\Illuminate\Validation\ValidationException $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $ex->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminanCSTB(Request $request)
    {
        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approveCSTBPenjaminan(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );
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
        $this->validate($request, [
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
        ]);

        $service = new CustomBondServicesCustomBondTransactionService;

        try {
            $result = $service->processUploadPembayaranManual($request);

            return response()->json([
                'success' => true,
                'message' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function submitDraft(Request $request, string $trxNo)
    {
        $this->validate($request, [
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
            'data.lampiranEdit.*.lampiran_id' => 'required|string',
            'data.tarif' => 'nullable|numeric|min:0'
        ]);

        $service = new CustomBondServicesCustomBondTransactionService();

        try {
            return $service->processSubmitDraft($request, $trxNo);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getCode() ?: 500);
        }
    }

    public function GetDetailPaymentCstb(Request $request)
    {
        $key = base64_decode(config('services.secure.key'));
        // dd($request);
        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $data = [];
            $resultPending = [];
            $dataPending = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('custom_bond_transaction as cbt', 'tph.trx_no', '=', 'cbt.trx_no')
                ->join('institution as inst', 'cbt.id_institution', '=', 'inst.id')
                ->join('custombond_tenor_schedule as cts', 'cbt.id_bond', '=', 'cts.id_bond')
                ->where('tph.trx_no', $trx_no)
                ->where('cts.status', 'Pending')
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'cts.cstb_schedule_id',
                    'cts.id_bond',
                    'inst.id_number',
                    'inst.id_type',
                    'inst.full_name',
                    'cts.amount',
                    'cts.invoice_number',
                    'cts.due_date',
                    'cts.status',
                    'cts.tenor_sequence'
                ])->first();
            $dataPending
                ? $resultPending[] = [
                    'schedule_id'     => $dataPending->cstb_schedule_id,
                    'id_trx_product'  => $dataPending->id_trx_product,
                    'id_number'       => AesHelper::decrypt($dataPending->id_number, $key),
                    'id_type'         => $dataPending->id_type,
                    'full_name'       => $dataPending->full_name,
                    'amount'          => $dataPending->amount,
                    'invoice_number'  => $dataPending->invoice_number,
                    'due_date'        => $dataPending->due_date,
                    'status'          => $dataPending->status,
                    'tenor_sequence'  => $isSplit ? $dataPending->tenor_sequence : 0,
                ]
                : $dataPending = [];

            $dataUnpaid  = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('custom_bond_transaction as cbt', 'tph.trx_no', '=', 'cbt.trx_no')
                ->join('institution as inst', 'cbt.id_institution', '=', 'inst.id')
                ->join('custombond_tenor_schedule as cts', 'cts.id_bond', '=', 'cbt.id_bond')
                ->join('trx_cstb_invoice_header as tcih', 'tcih.cstb_schedule_id', '=', 'cts.cstb_schedule_id')
                ->join('trx_cstb_payment_gateway as tcpg', 'tcpg.cstb_invoice_id', '=', 'tcih.cstb_invoice_id')
                ->where('tph.trx_no', $trx_no)
                ->where('tcih.status', 'Unpaid')
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tcpg.order_id',
                    'tcpg.cstb_payment_id as payment_id',
                    'tph.trx_no',
                    'tcpg.payment_amount_ijp as total_amount',
                    'tcpg.order_payment_token'
                ])
                ->get();
            $data = [
                'dataHeader' => [
                    'data_pending' => $resultPending,
                    'data_unpaid' => $dataUnpaid
                ]
            ];
            return response()->json($data);
        } catch (Exception $e) {
            Log::error("Error fetching payment details", [
                'exception' => $e,
                'trx_no' => $trx_no ?? null,
                'no_surat_permohonan' => $no_surat_permohonan ?? null
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
