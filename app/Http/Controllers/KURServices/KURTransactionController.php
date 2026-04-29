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
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('kur_transaction as kur', 'tph.trx_no', '=', 'kur.trx_no')
                ->where('tph.trx_no', $trx_no)
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tph.*',
                    'kur.id_kur',
                ])
                ->first();

            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }
            $dataDebitur = TrxDebiturDefaultBase::query()
                ->from('trx_debitur as td')
                ->join('institution as inst', 'td.institution_id', '=', 'inst.institution_id')
                ->where('td.kur_trx_id', $dataHeader->id_kur)
                ->select(
                    'td.id_trx_debitur',
                    'td.no_sp_detail',
                    'td.loan_number',
                    'td.tanggal_realisasi',
                    'inst.id_number',
                    'td.nama_nasabah'
                )
                ->orderBy('id_trx_debitur', 'asc')
                ->get();
            $debiturById = $dataDebitur->keyBy('id_trx_debitur');
            $debiturIds  = $dataDebitur->pluck('id_trx_debitur')->filter()->unique()->values();
            if ($debiturIds->isEmpty()) {
                return response()->json(['data' => []]);
            }
            $schedules = DebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
                ->WhereIn('status', ['Unpaid', 'Pending'])
                ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
                ->orderBy('tenor_sequence', 'asc')
                ->get();
            
            $schedulesUnpaid = DebiturInvoiceHeader::select(
                'dpg.payment_id',
                'dpg.order_id',
                'dpg.order_payment_url',
                'dpg.order_payment_token',
                'dts.tenor_sequence',
                'debitur_invoice_header.trx_no',
                'debitur_invoice_header.total_amount',
                DB::raw('COUNT(td.id_trx_debitur) as total_debitur')
            )
                ->join('debitur_tenor_schedule as dts', 'debitur_invoice_header.invoice_id', '=', 'dts.invoice_id')
                ->join('debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'debitur_invoice_header.invoice_id')
                ->join('trx_debitur as td', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
                // ->where('debitur_invoice_header.invoice_scope', '=', 'Merge Payment')
                ->where('dts.status', 'Unpaid')
                ->whereIn('dts.id_trx_debitur', $debiturIds)
                ->groupBy(
                    'dpg.order_id',
                    'dpg.order_payment_token',
                    'dpg.order_payment_url',
                    'dts.tenor_sequence',
                    'debitur_invoice_header.trx_no',
                    'debitur_invoice_header.total_amount'
                )
                ->get();
            $key = base64_decode(config('services.secure.key'));
            $result = $schedules
                ->groupBy('tenor_sequence')
                ->map(function ($rows, $tenor) use ($debiturById, $schedulesUnpaid, $key) {
                    $scheduleByDebitur = $rows->keyBy('id_trx_debitur');
                    $unpaidSchedules = $schedulesUnpaid->where('tenor_sequence', $tenor);
                    $listPending = $rows->where('status', 'Pending')->pluck('id_trx_debitur')->unique()->values()
                        ->map(function ($id) use ($debiturById, $scheduleByDebitur, $key) {
                            $d = $debiturById->get($id);
                            // dd($d);
                            if (!$d) return null;
                            $sch = $scheduleByDebitur->get($id);
                            return [
                                'id_trx_debitur'    => $d->id_trx_debitur,
                                'no_sp_detail'      => $d->no_sp_detail,
                                'loan_number'       => $d->loan_number,
                                'id_number'         => AesHelper::decrypt($d->id_number, $key),
                                'invoice_number'    => $sch->invoice_number,
                                'tanggal_realisasi' => $d->tanggal_realisasi,
                                'debitur_name'      => $d->nama_nasabah,
                                'due_date'          => $sch->due_date,
                                'status'            => $sch->status,
                                'amount'            => $sch?->amount,
                            ];
                        })->filter()->values();
                    $listUnpaid = $unpaidSchedules->map(function ($unpaid) {
                        return [
                            'payment_id'        => $unpaid->payment_id,
                            'order_payment_token' => $unpaid->order_payment_token,
                            'trx_no'            => $unpaid->trx_no,
                            'order_id'          => $unpaid->order_id,
                            'order_payment_url' => $unpaid->order_payment_url,
                            'total_debitur' => $unpaid->total_debitur,
                            'total_amount'      => $unpaid->total_amount,
                        ];
                    });

                    return [
                        'tenor' => (int) $tenor,
                        'invoice_number' => '',
                        'debitur_list_pending' => $listPending ?? null,
                        'debitur_list_unpaid' => $listUnpaid ?? null,
                    ];
                })->values();
            return response()->json([
                'data' => $result
            ]);
        } catch (Exception $ex) {
            Log::error("Error fetching payment details", [
                'exception' => $ex,
                'trx_no' => $trx_no ?? null,
                'no_surat_permohonan' => $no_surat_permohonan ?? null
            ]);

            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

}
