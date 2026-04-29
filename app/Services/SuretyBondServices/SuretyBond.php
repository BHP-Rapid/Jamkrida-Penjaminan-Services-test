<?php

namespace App\Services\SuretyBondServices;

use App\Helpers\AesHelper;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\SuretyBondTenorSchedule;
use App\Models\SuretyBondTransaction;
use App\Models\TenantMitra;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\TrxSrtbInvoiceHeader;
use App\Models\TrxSrtbPaymentGateway;
use App\Services\CreatioService;
use App\Services\PenjaminanService;
use Illuminate\Http\Request;
use App\Repositories\SuretyBondRepository;
use App\Services\InstitutionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuretyBond
{

    public function __construct(
        protected SuretyBondRepository $repository
    ) {}

    public function handleShow(array $request)
    {
        $user = auth('sanctum')->user();

        $mitraCode = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('alias')->first();

        if ($mitraCode == null) {
            return [
                'status' => 404,
                'response' => [
                    'success' => false,
                    'message' => 'No mitra code found.'
                ]
            ];
        }

        $trx_no = is_array($request)
            ? $request['trx_no']
            : $request->query('trx_no');

        $penjaminanData = $this->repository->getDetailByTrxNo($trx_no);

        if (!$penjaminanData) {
            throw new Exception('Data not found.', 404);
        }

        $penjaminanData->institution = $this->repository->getInstitution($penjaminanData->id_institution);

        $penjaminanData->history = $this->repository->getHistory($trx_no);

        $rawLampiran = $this->repository->getLampiran($trx_no);
        $lampiran = $this->mapLampiran($rawLampiran);
        $penjaminanData->lampiran = $lampiran;

        return $penjaminanData;
    }

    public function handleStore(array $payload)
    {
        $user = auth('sanctum')->user();

        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant mitra data not found.'
            ], 404);
        }
        $mitraAlias = $tenantMitraData->alias;
        $penjaminanPayload = collect($payload['data'])->toArray();
        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }
        $isBastPenjaminan = array_key_exists('isBast', $penjaminanPayload)
            ? $penjaminanPayload['isBast']
            : false;

        [$hasLampiran, $duplicateLampiranId] = $this->validateLampiran($penjaminanPayload);

        if ($duplicateLampiranId) {
            return new Exception('Duplicate lampiran id.', 422);
            // return response()->json([
            //     'success' => false,
            //     'message' => 'Duplicate lampiran id.'
            // ], 422);
        }

        $institutionService = new InstitutionService();
        $institutionPayload = $this->buildInstitutionPayload($payload);

        $trxInsertStatus = $penjaminanPayload['status'] == 'submit' ? 'NA' : 'D';

        $institutionIsInserted = false;

        try {
            $institutionService->insertInstitution($institutionPayload, $user->user_id);
            $institutionIsInserted = true;
        } catch (ValidationException $ve) {
            return response()->json([
                'message' => $ve->getMessage(),
                'error' => $ve->errors()
            ], 422);
        } catch (Exception $e) {
            return new Exception('Error inserting institution (' . $e->getMessage() . ')', 500);
            // return response()->json([
            //     'success' => false,
            //     'message' => 'Error inserting institution (' . $e->getMessage() . ')'
            // ], 500);
        }


        return DB::transaction(function () use (
            $penjaminanPayload,
            $user,
            $mitraAlias,
            $trxInsertStatus,
            $institutionService,
            $hasLampiran,
        ) {
            $trxNo = $this->generateTrxNo();
            $nowJakarta = now('Asia/Jakarta');

            $idInstitution = DB::table('institution')
                ->where('institution_id', $institutionService->getCreatedInstitutionId())
                ->value('id'); // 🔥 lebih simple dari select()->first()

            $fallback = function ($key, $default = null) use ($penjaminanPayload) {
                return $penjaminanPayload[$key] ?? $default;
            };

            // Header
            $this->repository->createHeader([
                'trx_no' => $trxNo,
                'no_surat_permohonan' => $fallback('noSuratPermohonan', 'DRAFT-' . $trxNo),
                'sp_split' => $fallback('isSplit'),
                'trx_status' => $trxInsertStatus,
                'status_sync_creatio' => 0,
                'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan', $nowJakarta),
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'no_rek' => '123',
                'mitra_id' => $mitraAlias,
                'product' => 'srtb'
            ]);
            $this->repository->createDetail(
                $this->buildSrtbPayload($penjaminanPayload, $fallback, $trxNo, $idInstitution)
            );
            if ($hasLampiran) {
                $attachments = $this->handleLampiran($trxNo, $penjaminanPayload);

                if (!empty($attachments)) {
                    $this->repository->insertLampiran($attachments);
                }
            }
            $this->repository->insertFlow([
                'trx_no' => $trxNo,
                'trx_status' => $trxInsertStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name
            ]);

            return [
                'trx_no' => $trxNo
            ];
        });
    }

    public function handleSubmitDraft(Request $request, string $trxNo)
    {
        $user = auth('sanctum')->user();
        $payload = collect($request->data)->toArray();
        if (array_key_exists('institution_data', $payload)) {
            unset($payload['institution_data']);
        }
        $lampiranExist = array_key_exists('lampiranEdit', $payload) && $payload['lampiranEdit'];
        $duplicateLampiranId = false;
        if ($lampiranExist) {
            $ids = array_column($payload['lampiranEdit'], 'lampiran_id');
            $duplicateLampiranId = count(array_unique($ids)) != count($ids);
        }

        if ($duplicateLampiranId) {
            throw new Exception('Duplicate lampiran id.', 422);
        }

        return DB::transaction(function () use ($trxNo, $payload, $user, $lampiranExist) {
            $data = $this->repository->getHeaderWithDetail($trxNo);
            if (!$data) {
                throw new Exception('Penjaminan surety bond data is not found.', 404);
            }
            if ($data->trx_status !== 'D') {
                throw new Exception('Data is not in draft status.', 422);
            }
            // Update header
            $this->repository->updateHeader($trxNo, [
                'no_surat_permohonan' => $payload['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $payload['tglSuratPermohonan'],
                'sp_split' => $payload['isSplit'],
                'trx_status' => 'NA',
                'updated_by_id' => $user->user_id,
                'updated_by_name' => $user->name
            ]);

            // Update detail
            $this->repository->updateDetail($trxNo, [
                'jenis_bond' => $payload['jenisBond'],
                'jenis_persyaratan' => $payload['jenisPernyataan'],
                'skema_penalty' => $payload['skemaPenalty'],
                'jenis_surat_perjanjian' => $payload['jenisSuratPerjanjian'],
                'no_surat_perjanjian' => $payload['noSuratPerjanjian'],
                'tgl_surat_perjanjian' => $payload['tglSuratPerjanjian'],
                'sektor' => $payload['sektor'],
                'principal_name' => $payload['namaPrincipal'],
                'obligee_name' => $payload['namaObligee'],
                'is_bast' => $payload['isBast'],
                'no_surat_bast' => $payload['isBast'] ? $payload['noSuratBast'] : null,
                'bast_date' => $payload['isBast'] ? $payload['tglSuratBast'] : null,
                'project_name' => $payload['namaProyek'],
                'project_amount' => $payload['nilaiProyek'],
                'amount_bond' => $payload['nilaiBond'],
                'bond_percentage' => $payload['nilaiBondPersentase'],
                'start_period_date' => $payload['periodeAwalBerlaku'],
                'end_period_date' => $payload['periodeAkhirBerlaku'],
                'total_day' => $payload['jangkaWaktu'],
                'province' => $payload['propinsi'],
                'agunan_amount' => $payload['nilaiAgunan']
            ]);

            // Handle attachment
            if ($lampiranExist) {
                $attachments = $this->handleLampiran($trxNo, $payload['lampiranEdit']);

                if (!empty($attachments)) {
                    $this->repository->insertLampiran($attachments);
                }
            }

            // Flow
            $this->repository->insertFlow([
                'trx_no' => $trxNo,
                'trx_status' => 'NA',
                'created_at' => now('Asia/Jakarta'),
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name
            ]);

            return 'Penjaminan Surety Bond successfully submitted.';
        });
    }


    public function handleApprovePenjaminanSB(Request $request)
    {
        $trx_no = $request->trxNo;
        $user = auth('sanctum')->user();

        try {
            (new PenjaminanService())->approveSuretyBondPenjaminan(
                $trx_no,
                $user->user_id,
                $user->name,
                "Perorangan"
            );

            return 'Penjaminan Surety Bond successfully approved.';
        } catch (Exception $ex) {
            throw new Exception(
                'Error while approving Penjaminan Surety Bond (' . $ex->getMessage() . ')',
                500
            );
        }
    }

    public function handleGetDetailPaymentSrtb(array $payload)
    {
        $trx_no = $payload['trx_no'];
        $isSplit = $payload['is_split'];
        $key = $payload['key'];
        $resultPending = [];
        $dataPending = $this->repository->getPendingData($trx_no, $isSplit);
        foreach ($dataPending as $pending) {
            $decryptedIdNumber = AesHelper::decrypt($pending->id_number, $key);
            $numAmount = (int) $pending->amount;
            $numCollateralAmount = (int) $pending->collateral_amount;

            if ($pending->status == 'Pending') {
                $resultPending[] = [
                    'schedule_id' => $pending->srtb_schedule_id,
                    'id_trx_product' => $pending->id_trx_product,
                    'id_number' => $decryptedIdNumber,
                    'id_type' => $pending->id_type,
                    'full_name' => $pending->full_name,
                    'amount' => $numAmount,
                    'invoice_number' => $pending->invoice_number,
                    'due_date' => $pending->due_date,
                    'status' => $pending->status,
                    'tenor_sequence' => $isSplit ? $pending->tenor_sequence : 0,
                    'is_collateral' => false
                ];
            }

            if (
                !empty($pending->invoice_number_collateral) &&
                $pending->status_collateral == 'Pending'
            ) {
                $resultPending[] = [
                    'schedule_id' => $pending->srtb_schedule_id,
                    'id_trx_product' => $pending->id_trx_product,
                    'id_number' => $decryptedIdNumber,
                    'id_type' => $pending->id_type,
                    'full_name' => $pending->full_name,
                    'amount' => $numCollateralAmount,
                    'invoice_number' => $pending->invoice_number_collateral,
                    'due_date' => $pending->due_date,
                    'status' => $pending->status_collateral,
                    'tenor_sequence' => $isSplit ? $pending->tenor_sequence : 0,
                    'is_collateral' => true
                ];
            }
        }
        $dataUnpaid = $this->repository->getUnpaidData($trx_no, $isSplit);
        return [
            'dataHeader' => [
                'data_pending' => $resultPending,
                'data_unpaid' => $dataUnpaid
            ]
        ];
    }

    public function handleUploadPembayaranManual(array $payload)
    {
        $user = auth('sanctum')->user();
        $nowJakarta = Carbon::now('Asia/Jakarta');
        $parsedItem = $payload['selected_items'];
        $selectedInvoiceNumbers = array_column($parsedItem, 'invoice_number');

        $invoiceNumbers = collect($parsedItem)->pluck('invoice_number')->toArray();

        if (count($invoiceNumbers) != count(array_unique($invoiceNumbers))) {
            throw ValidationException::withMessages([
                'selected_items' => ['Duplicate invoice data.']
            ]);
        }

        $normalItems = collect($parsedItem)->where('is_collateral', '!=', true);
        $collateralItems = collect($parsedItem)->where('is_collateral', true);

        $arrNormalInvoice = $normalItems->pluck('invoice_number')->toArray();
        $arrCollateralInvoice = $collateralItems->pluck('invoice_number')->toArray();

        return DB::transaction(function () use (
            $payload,
            $user,
            $nowJakarta,
            $parsedItem,
            $selectedInvoiceNumbers,
            $arrNormalInvoice,
            $arrCollateralInvoice
        ) {
            $header = PenjaminanTransaction::where('trx_no', $payload['trx_no'])
                ->select('no_surat_permohonan')
                ->first();

            $tenorData = SuretyBondTenorSchedule::query()
                ->from('surety_bond_transaction as sbt')
                ->join('suretybond_tenor_schedule as srbs', 'sbt.id_trx_product', '=', 'srbs.id_trx_product')
                ->select([
                    'srbs.srtb_schedule_id',
                    'sbt.id_trx_product',
                    'sbt.trx_no',
                    'srbs.tenor_sequence',
                    'srbs.invoice_number',
                    'srbs.invoice_number_collateral',
                    'srbs.amount',
                    'srbs.collateral_amount',
                    'srbs.status',
                    'srbs.status_collateral'
                ])
                ->where(function ($q) use ($arrNormalInvoice, $arrCollateralInvoice) {
                    $q->where(function ($sub) use ($arrNormalInvoice) {
                        $sub->where('status', 'Pending')
                            ->whereIn('srbs.invoice_number', $arrNormalInvoice);
                    })->orWhere(function ($sub) use ($arrCollateralInvoice) {
                        $sub->where(function ($s) {
                            $s->whereNull('status_collateral')
                                ->orWhere('status_collateral', 'Pending');
                        })->whereIn('srbs.invoice_number_collateral', $arrCollateralInvoice);
                    });
                })
                ->where('sbt.trx_no', $payload['trx_no'])
                ->get();

            if ($tenorData->count() < 1 || !$header) {
                throw new HttpException(404, 'Penjaminan Surety Bond not found or no payment data.');
            }

            $noSP = $header->no_surat_permohonan;

            $headerPayments = [];
            $debiturPayload = [];
            $totalAmount = 0;

            foreach ($tenorData as $row) {

                $paymentScope = 'Permohonan Payment';
                $paymentAmount = 0;

                $collateralIndex = !empty($row->invoice_number_collateral)
                    ? array_search($row->invoice_number_collateral, $selectedInvoiceNumbers)
                    : false;

                $permohonanIndex = array_search(
                    $row->invoice_number,
                    $selectedInvoiceNumbers
                );

                if (is_numeric($collateralIndex)) {
                    $paymentScope = 'Collateral Payment';
                    $paymentAmount += (int) $row->collateral_amount;

                    $debiturPayload[] = [
                        'no_sp_detail' => $noSP,
                        'invoice_number' => $row->invoice_number_collateral,
                        'total_amount' => (int) $row->collateral_amount
                    ];
                }

                if (is_numeric($permohonanIndex)) {
                    $paymentScope = is_numeric($collateralIndex)
                        ? 'Merge Payment'
                        : $paymentScope;

                    $paymentAmount += (int) $row->amount;

                    $debiturPayload[] = [
                        'no_sp_detail' => $noSP,
                        'invoice_number' => $row->invoice_number,
                        'total_amount' => (int) $row->amount
                    ];
                }

                $totalAmount += $paymentAmount;

                $headerPayments[] = [
                    'srtb_schedule_id' => $row->srtb_schedule_id,
                    'id_trx_product' => $row->id_trx_product,
                    'trx_no' => $row->trx_no,
                    'tenor_sequence' => $row->tenor_sequence,
                    'invoice_number' => $row->invoice_number,
                    'invoice_number_collateral' => $row->invoice_number_collateral,
                    'amount' => $row->amount,
                    'collateral_amount' => $row->collateral_amount,
                    'invoice_scope' => $paymentScope,
                    'total_amount' => $paymentAmount,
                    'status' => is_numeric($permohonanIndex) ? 'Paid' : $row->status,
                    'status_collateral' => is_numeric($collateralIndex) ? 'Paid' : $row->status_collateral
                ];
            }

            if ($totalAmount != (int) $payload['amount']) {
                throw ValidationException::withMessages([
                    'amount' => ['Incorrect amount.']
                ]);
            }

            $orderIds = [];

            foreach ($headerPayments as $item) {

                $invoice = TrxSrtbInvoiceHeader::create([
                    'srtb_schedule_id' => $item['srtb_schedule_id'],
                    'invoice_scope' => $item['invoice_scope'],
                    'total_amount' => $item['total_amount'],
                    'status' => 'Paid',
                    'is_manual' => 1
                ]);

                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

                TrxSrtbPaymentGateway::create([
                    'srtb_invoice_id' => $invoice->srtb_invoice_id,
                    'payment_amount_ijp' => $item['total_amount'],
                    'order_id' => $orderId
                ]);

                SuretyBondTenorSchedule::where('srtb_schedule_id', $item['srtb_schedule_id'])
                    ->update([
                        'status' => $item['status'],
                        'status_collateral' => $item['status_collateral']
                    ]);

                $orderIds[] = $orderId;
            }

            $file = $payload['file'];
            $ext = $file->getClientOriginalExtension();
            $filename = $orderIds[0] . '-pembayaran-srtb';

            $base64 = base64_encode(file_get_contents($file->path()));

            $creatio = new CreatioService();

            $response = $creatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', [
                'NoSuratPermohonan' => $noSP,
                'ListDebitur' => $debiturPayload,
                'NamaFile' => "$filename.$ext",
                'DataBase64' => $base64
            ]);

            if ($response->status() !== 200) {
                throw new Exception("Failed send to Creatio. Status: " . $response->status());
            }

            $body = json_decode($response->body(), true);

            if ($body['Success'] !== true) {
                throw new Exception("Creatio Error: " . $body['Message']);
            }

            $path = $file->storeAs(
                'uploads/penjaminan/payment-surety-bond',
                "$filename.$ext",
                's3'
            );

            PenjaminanLampiranDtl::create([
                'trx_no' => $payload['trx_no'],
                'lampiran_id' => 'pembayaran',
                'file_name' => $filename,
                'status_doc' => 'N',
                'version' => 1,
                'mime_type' => $file->getMimeType(),
                'file_info' => $path
            ]);

            PenjaminanTransaction::where('trx_no', $payload['trx_no'])
                ->update([
                    'trx_status' => 'PD',
                    'updated_at' => $nowJakarta
                ]);

            PenjaminanFlow::insert([
                'trx_no' => $payload['trx_no'],
                'trx_status' => 'PD',
                'created_at' => $nowJakarta,
                'updated_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name
            ]);

            return 'Bukti bayar manual uploaded successfully.';
        });
    }


    private function mapLampiran($docList)
    {
        return collect($docList)->map(function ($item) {
            $fileUrl = null;
            $filePath = '';

            if ($item->lampiran_id != null) {
                $decodedInfo = json_validate($item->file_info)
                    ? json_decode($item->file_info)
                    : null;

                $filePath = $decodedInfo != null && isset($decodedInfo->path)
                    ? $decodedInfo->path
                    : $item->file_info;

                $fileUrl = Storage::disk('s3')->temporaryUrl(
                    $filePath,
                    now()->addMinutes(15)
                );
            }

            return [
                'key_lampiran'   => $item->value,
                'label_lampiran' => $item->label,
                'option_type'    => $item->option2,
                'file_name'      => $item->file_name,
                'file_path'      => $filePath,
                'is_additional'  => $item->is_additional,
                'status_doc'     => $item->status_doc,
                'mime_type'      => $item->mime_type,
                'presigned_url'  => $fileUrl
            ];
        })->toArray();
    }

    private function handleLampiran(string $trxNo, array $lampiranEdit)
    {
        $existing = $this->repository->getLatestLampiranVersion($trxNo);

        $saved = [];

        foreach ($lampiranEdit as $item) {
            $file = $item['file'];

            $ext = $file->getClientOriginalExtension();
            $fn = "{$trxNo}-{$item['lampiran_id']}-srtb-" . uniqid();

            $path = $file->storeAs(
                'uploads/penjaminan/surety-bond',
                "$fn.$ext",
                's3'
            );

            $currentVersion = $existing[$item['lampiran_id']] ?? 0;

            $saved[] = [
                'trx_no' => $trxNo,
                'lampiran_id' => $item['lampiran_id'],
                'file_name' => $fn,
                'status_doc' => 'N',
                'version' => $currentVersion + 1,
                'mime_type' => $file->getMimeType(),
                'file_info' => $path
            ];
        }

        return $saved;
    }

    private function validateLampiran($payload)
    {
        $hasLampiran = false;
        $duplicate = false;

        if (array_key_exists('lampiran', $payload) && !empty($payload['lampiran'])) {
            $ids = array_column($payload['lampiran'], 'lampiran_id');
            $duplicate = count($ids) !== count(array_unique($ids));
            $hasLampiran = true;
        }

        return [$hasLampiran, $duplicate];
    }


    private function buildInstitutionPayload($request)
    {
        $data = collect($request->data['institution_data'])->toArray();

        $data['category'] = 'P';
        $data['id_issued_location'] = '-';
        $data['phone_type'] = '-';

        return $data;
    }

    private function generateTrxNo()
    {
        $year = date('Y');
        $month = date('m');

        $last = PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', "PNJ-$year-$month%")
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');

        $next = $last ? intval(substr($last, -4)) + 1 : 1;

        return "PNJ-$year-$month-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    private function buildSrtbPayload($payload, $fallback, $trxNo, $idInstitution)
    {
        return [
            'trx_no' => $trxNo,
            'jenis_bond' => $payload['jenisBond'],
            'jenis_bond_description' => $payload['jenisBondDescription'],
            'jenis_persyaratan' => $fallback('jenisPernyataan'),
            'skema_penalty' => $fallback('skemaPenalty'),
            'sektor' => $fallback('sektor'),
            'principal_name' => $fallback('namaPrincipal'),
            'obligee_name' => $fallback('namaObligee'),
            'id_institution' => $idInstitution->id,
            'is_bast' => $fallback('isBast'),
            'no_surat_bast' => ($payload['isBast'] ?? false) ? $fallback('noSuratBast') : null,
            'bast_date' => ($payload['isBast'] ?? false) ? $fallback('tglSuratBast') : null,
            'project_name' => $fallback('namaProyek'),
            'project_amount' => $fallback('nilaiProyek'),
            'bond_percentage' => $fallback('nilaiBondPersentase'),
            'amount_bond' => $fallback('nilaiBond'),
            'start_period_date' => $fallback('periodeAwalBerlaku'),
            'total_day' => $fallback('jangkaWaktu'),
            'end_period_date' => $fallback('periodeAkhirBerlaku'),
            'province' => $fallback('propinsi'),
            'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
            'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
            'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
            'agunan_amount' => $fallback('nilaiAgunan'),
        ];
    }
}
