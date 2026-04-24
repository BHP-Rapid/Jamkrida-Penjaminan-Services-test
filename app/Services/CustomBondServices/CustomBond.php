<?php

namespace App\Services\CustomBondServices;

use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Models\CustomBondTenorSchedule;
use App\Models\CustomBondTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxCstbInvoiceHeader;
use App\Models\TrxCstbPaymentGateway;
use App\Services\CreatioService;
use App\Services\InstitutionService;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomBond
{
    public function getDetail($trx_no, $no_surat_permohonan)
    {
        $key = base64_decode(config('services.secure.key'));

        $penjaminanCustomBond = PenjaminanTransaction::join(
            'custom_bond_transaction as cbt',
            'transaction_penjaminan_header.trx_no',
            '=',
            'cbt.trx_no'
        )
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->where('transaction_penjaminan_header.no_surat_permohonan', $no_surat_permohonan)
            ->select('transaction_penjaminan_header.*', 'cbt.*')
            ->first();

        if (!$penjaminanCustomBond) {
            return null;
        }

        $institutionData = DB::table('institution as a')
            ->join('custom_bond_transaction as b', 'a.id', '=', 'b.id_institution')
            ->where('b.id_institution', $penjaminanCustomBond->id_institution)
            ->select('a.*')
            ->first();

        if ($institutionData) {
            $this->decryptInstitution($institutionData, $key);
        }

        $lampiran = $this->getLampiran($trx_no);

        $penjaminanCustomBond->setAttribute('lampiran', $lampiran);

        $flow = PenjaminanFlow::where('trx_no', $trx_no)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($flow) {
            $penjaminanCustomBond->institutionData = $institutionData;
        }

        return $penjaminanCustomBond;
    }

    private function decryptInstitution($data, $key)
    {
        $fields = [
            'phone_1',
            'email_1',
            'birth_date',
            'id_number',
            'tax_id',
            'current_salary_amount',
            'other_income_amount'
        ];

        foreach ($fields as $field) {
            $data->$field = !empty($data->$field)
                ? AesHelper::decrypt($data->$field, $key)
                : null;
        }
    }

    private function getLampiran($trx_no)
    {
        $penjaminanVersionMax = PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select(
                'trx_no',
                'lampiran_id',
                DB::raw('MAX(version) as latest_version')
            )
            ->groupBy('trx_no', 'lampiran_id');

        $lampiranLatest = PenjaminanLampiranDtl::joinSub(
            $penjaminanVersionMax,
            'latest',
            function ($join) {
                $join->on('penjaminan_lampiran_dtl.trx_no', '=', 'latest.trx_no')
                    ->on('penjaminan_lampiran_dtl.lampiran_id', '=', 'latest.lampiran_id')
                    ->on('penjaminan_lampiran_dtl.version', '=', 'latest.latest_version');
            }
        )->select(
            'penjaminan_lampiran_dtl.lampiran_id',
            'penjaminan_lampiran_dtl.file_name',
            'penjaminan_lampiran_dtl.file_info',
            'penjaminan_lampiran_dtl.is_additional',
            'penjaminan_lampiran_dtl.status_doc',
            'penjaminan_lampiran_dtl.mime_type',
            'penjaminan_lampiran_dtl.version'
        );

        $lampiranData = DB::table('setting_hdr as a')
            ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
            ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
            ->leftJoinSub($lampiranLatest, 'lt', function ($join) {
                $join->on('lt.lampiran_id', '=', 'c.value');
            })
            ->select(
                'c.value',
                'c.label',
                'c.option2',
                'lt.file_name',
                'lt.file_info',
                'lt.is_additional',
                'lt.status_doc',
                'lt.mime_type'
            )
            ->where('a.module', 'PENJAMINAN_SETTINGS')
            ->where('b.product_id', 'cstb')
            ->where('a.mitra_id', 'MDR')
            ->where('b.is_mandatory', 1)
            ->where('c.key', 'lampiran')
            ->orderBy('c.value', 'asc')
            ->get();

        return $lampiranData->map(function ($att) {

            $file = $att->file_info ? json_decode($att->file_info) : null;
            $filePath = $file ? $file->path : null;

            return [
                'file_name' => $att->file_name ?? basename($filePath ?? ''),
                'file_path' => $filePath,
                'key_lampiran' => $att->value,
                'label_lampiran' => $att->label,
                'mime_type' => $att->mime_type,
                'option_type' => $att->option2,
                'is_additional' => (int) ($att->is_additional ?? 0),
                'status_doc' => $att->status_doc ?? 'N',
                'presigned_url' => $filePath
                    ? Storage::disk('s3')->temporaryUrl($filePath, now()->addMinutes(15))
                    : null,
            ];
        })->values()->toArray();
    }

    public function store($payload, $user)
    {
        $institutionIsInserted = false;
        $mitraAlias = '';
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();
        if (!$tenantMitraData) {
            return [
                'error' => true,
                'code' => 404,
                'message' => 'Tenant mitra data not found.'
            ];
        }
        $mitraAlias = $tenantMitraData->alias;
        $penjaminanPayload = $payload['data'] ?? [];
        $checkIsDeposit = (bool) ($penjaminanPayload['isDeposit'] ?? false);
        $institutionService = new InstitutionService();
        $institutionKeys = [
            'full_name',
            'birth_place',
            'birth_date',
            'home_address',
            'home_province',
            'home_city',
            'home_district',
            'home_sub_district',
            'home_zipcode',
            'id_type',
            'id_number',
            'id_issued_location',
            'phone_1',
            'email_1',
            'gender',
            'mother_name',
            'tax_type',
            'tax_id',
            'job_id',
            'job_level',
            'job_employer_name',
            'job_start_date',
            'job_industry_type',
            'current_salary_amount',
            'current_salary_currency',
            'other_income_source',
            'other_income_type',
            'other_income_currency',
            'other_income_amount'
        ];
        $institutionData = $penjaminanPayload['institutionData'] ?? [];
        $institutionPayload = collect($institutionData)
            ->only($institutionKeys)
            ->toArray();

        $institutionPayload['category'] = 'P';
        $institutionPayload['phone_type'] = '-';
        $trxInsertStatus = ($penjaminanPayload['trx_status'] ?? null) === 'NA' ? 'NA' : 'D';
        try {
            $institutionService->insertInstitution($institutionPayload, $user->user_id);
            $institutionIsInserted = true;
        } catch (ValidationException $ve) {
            throw $ve;
        } catch (Exception $e) {
            throw new Exception('Error inserting institution (' . $e->getMessage() . ')');
        }

        DB::beginTransaction();

        try {
            $this->handleTransaction(
                $user,
                $trxInsertStatus,
                $institutionService,
                $penjaminanPayload,
                $mitraAlias
            );

            DB::commit();

            return ApiResponse::success(null, 'Store Custom Bond has success');
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function handleTransaction($user, $trxInsertStatus, $institutionService, $penjaminanPayload, $mitraAlias)
    {
        $idInstitution = DB::table('institution')
            ->where('institution_id', $institutionService->getCreatedInstitutionId())
            ->select('id')->first();

        $currentYear = date('Y');
        $currentMonth = date('m');

        $lastTrx = PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', 'PNJ-' . $currentYear . '-' . $currentMonth . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');

        $nextSeq = $lastTrx ? intval(substr($lastTrx, -4)) + 1 : 1;

        $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

        $permohonanDate = Carbon::parse($penjaminanPayload['tglSuratPermohonan'])->format('Y-m-d');
        $nowJakarta = Carbon::now('Asia/Jakarta');

        PenjaminanTransaction::create([
            'trx_no' => $trxNo,
            'no_surat_permohonan' => $penjaminanPayload['noSuratPermohonan'],
            'trx_status' => $trxInsertStatus,
            'status_sync_creatio' => 0,
            'tanggal_surat_permohonan' => $permohonanDate,
            'no_rek' => '123x',
            'created_by_name' => $user->name,
            'created_at' => $nowJakarta,
            'created_by_id' => $user->user_id,
            'sp_split' => $penjaminanPayload['isSplit'] ?? 0,
            'mitra_id' => $mitraAlias,
            'product' => 'cstb',
        ]);

        CustomBondTransaction::create([
            'trx_no' => $trxNo,
            'jenis_bond' => $penjaminanPayload['jenisBond'],
            'jenis_bond_description' => $penjaminanPayload['descriptionBond'],
            'principal_name' => $penjaminanPayload['namaPrincipal'],
            'obligee_name' => $penjaminanPayload['namaObligee'],
            'id_institution' => $idInstitution->id,
            'jenis_persyaratan' => $penjaminanPayload['jenisPernyataan'],
            'skema_penalty' => $penjaminanPayload['skemaPenalty'],
            'sektor' => $penjaminanPayload['sektor'],
            'document_name' => $penjaminanPayload['documentName'],
            'document_number' => $penjaminanPayload['documentNumber'],
            'document_date' => Carbon::parse($penjaminanPayload['documentDate'])->format('Y-m-d'),
            'is_deposit' => $penjaminanPayload['isDeposit'] ?? false,
            'is_bast' => $penjaminanPayload['isBast'],
            'no_surat_bast' => $penjaminanPayload['isBast'] ? $penjaminanPayload['noSuratBast'] : null,
            'bast_date' => $penjaminanPayload['isBast'] ? Carbon::parse($penjaminanPayload['tglSuratBast'])->format('Y-m-d') : null,
            'project_name' => $penjaminanPayload['namaProyek'],
            'project_amount' => $penjaminanPayload['nilaiProyek'],
            'amount_bond' => $penjaminanPayload['nilaiBond'],
            'no_surat_perjanjian' => $penjaminanPayload['noSuratPerjanjian'],
            'jenis_surat_perjanjian' => $penjaminanPayload['jenisSuratPerjanjian'],
            'bond_percentage' => $penjaminanPayload['nilaiBondPersentase'],
            'start_period_date' => Carbon::parse($penjaminanPayload['periodeAwalBerlaku'])->format('Y-m-d'),
            'tgl_surat_perjanjian' => Carbon::parse($penjaminanPayload['tglSuratPerjanjian'])->format('Y-m-d'),
            'end_period_date' => Carbon::parse($penjaminanPayload['periodeAkhirBerlaku'])->format('Y-m-d'),
            'total_day' => $penjaminanPayload['jangkaWaktu'],
            'province' => $penjaminanPayload['propinsi'],
            'tarif_percentage' => $penjaminanPayload['tarif'] ?? 0,
            'ijp_amount' => $penjaminanPayload['ijpAmount'] ?? 0,
            'administrative_amount' => $penjaminanPayload['administrativeAmount'] ?? 0,
            'stamp_amount' => $penjaminanPayload['stampAmount'] ?? 0,
            'created_at' => $nowJakarta,
        ]);

        if (array_key_exists('attachments', $penjaminanPayload)) {
            foreach ($penjaminanPayload['attachments'] as $item) {
                $lampiran = $this->lampiranCustomBond($trxNo, 'cstb', $item);

                PenjaminanLampiranDtl::create([
                    "trx_no" => $trxNo,
                    "lampiran_id" => $lampiran["lampiran_id"],
                    "file_name" => $lampiran["file_name"],
                    "file_info" => $lampiran["file_info"],
                    "mime_type" => $lampiran["mime_type"],
                    "status_doc" => $lampiran["status_doc"],
                    "version" => $lampiran["version"]
                ]);
            }
        }

        PenjaminanFlow::create([
            'trx_no' => $trxNo,
            'trx_status' => $trxInsertStatus,
            'created_at' => $nowJakarta,
            'created_by_id' => $user->user_id,
            'created_by_name' => $user->name,
            'updated_at' => null
        ]);
    }

    public function lampiranCustomBond($trx_no, $product, $lampiran)
    {
        try {
            $fn = $trx_no . "-" . $lampiran['lampiran_id'] . "-" . $product;
            $file = $lampiran['file'];

            $fileExisting = PenjaminanLampiranDtl::where('file_name', $fn)
                ->select('file_info')->first();

            $exist = json_decode($fileExisting);

            if ($file instanceof \Illuminate\Http\UploadedFile) {

                $old_file_path = $exist['path'] ?? null;

                if ($old_file_path) {
                    Storage::disk('s3')->delete($old_file_path);
                }

                $path = $file->storeAs(
                    'uploads/penjaminan/custom-bond',
                    $fn . "." . $file->getClientOriginalExtension(),
                    's3'
                );
            } else {
                throw new Exception('somethings is wrong with the file');
            }

            $file_path = json_encode([
                "path" => $path,
                "file_type" => $file->getMimeType()
            ]);

            return [
                "trx_no" => $trx_no,
                "lampiran_id" => strtolower($lampiran['lampiran_id']),
                "file_name" => $fn,
                "file_info" => $file_path,
                "mime_type" => $file->getMimeType(),
                "status_doc" => "N",
                "version" => 1
            ];
        } catch (Exception $ex) {
            DB::rollBack();
            throw new Exception('Error While Insert Custom Bond: ' . $ex->getMessage());
        }
    }

    public function updateDraft($payload, $trxNo, $user)
    {
        $penjaminanPayload = $payload['data'] ?? [];
        unset($penjaminanPayload['institution_data']);
        $isBastPenjaminan = $penjaminanPayload['isBast'] ?? false;
        if ($isBastPenjaminan) {
            if (empty($penjaminanPayload['noSuratBast']) || empty($penjaminanPayload['tglSuratBast'])) {
                throw new HttpException(
                    422,
                    'No Surat BAST and Tanggal BAST are required when isBast = true'
                );
            }
        }
        $lampiranExist = !empty($penjaminanPayload['attachments'] ?? []);
        if ($lampiranExist) {
            $lampiranIdMap = array_map(
                fn($item) => $item['lampiran_id'],
                $penjaminanPayload['attachments']
            );
            if (count(array_unique($lampiranIdMap)) !== count($lampiranIdMap)) {
                throw new HttpException(422, 'Duplicate lampiran id.');
            }
        }
        DB::beginTransaction();
        $penjaminanTrxHeaderData = PenjaminanTransaction::where('trx_no', $trxNo)
            ->select('trx_no', 'trx_status')
            ->first();

        $customBondData = CustomBondTransaction::where('trx_no', $trxNo)
            ->select('id_bond')
            ->first();

        if (!$penjaminanTrxHeaderData || !$customBondData) {
            throw new HttpException(400, 'Penjaminan data is not found.');
        }

        if ($penjaminanTrxHeaderData->trx_status !== 'D') {
            throw new HttpException(422, 'Data is not draft.');
        }

        $fallback = function (string $key, $default = null) use ($penjaminanPayload) {
            return array_key_exists($key, $penjaminanPayload)
                ? $penjaminanPayload[$key]
                : $default;
        };

        $nowJakarta = Carbon::now('Asia/Jakarta');
        PenjaminanTransaction::where('trx_no', $trxNo)->update([
            'no_surat_permohonan' => $fallback('noSuratPermohonan'),
            'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan'),
            'is_split' => $fallback('isSplit'),
            'updated_by_id' => $user->user_id,
            'updated_by_name' => $user->name,
            'updated_at' => $nowJakarta
        ]);
        CustomBondTransaction::where('trx_no', $trxNo)->update([
            'trx_no' => $trxNo,
            'jenis_bond' => 'cstb',
            'jenis_bond_description' => 'Custom Bond',

            'jenis_persyaratan' => $fallback('jenisPernyataan'),
            'skema_penalty' => $fallback('skemaPenalty'),
            'sektor' => $fallback('sektor'),

            'document_name' => $fallback('documentName'),
            'document_number' => $fallback('documentNumber'),
            'document_date' => !empty($penjaminanPayload['documentDate'])
                ? Carbon::parse($penjaminanPayload['documentDate'])->format('Y-m-d')
                : null,

            'is_bast' => $isBastPenjaminan,
            'no_surat_bast' => $isBastPenjaminan ? $fallback('noSuratBast') : null,
            'bast_date' => $isBastPenjaminan && !empty($penjaminanPayload['tglSuratBast'])
                ? Carbon::parse($penjaminanPayload['tglSuratBast'])->format('Y-m-d')
                : null,

            'project_name' => $fallback('namaProyek'),
            'project_amount' => $fallback('nilaiProyek'),
            'amount_bond' => $fallback('nilaiBond'),

            'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
            'bond_percentage' => $fallback('nilaiBondPersentase'),

            'start_period_date' => !empty($penjaminanPayload['periodeAwalBerlaku'])
                ? Carbon::parse($penjaminanPayload['periodeAwalBerlaku'])->format('Y-m-d')
                : null,

            'tgl_surat_perjanjian' => !empty($penjaminanPayload['tglSuratPerjanjian'])
                ? Carbon::parse($penjaminanPayload['tglSuratPerjanjian'])->format('Y-m-d')
                : null,

            'end_period_date' => !empty($penjaminanPayload['periodeAkhirBerlaku'])
                ? Carbon::parse($penjaminanPayload['periodeAkhirBerlaku'])->format('Y-m-d')
                : null,

            'total_day' => $fallback('jangkaWaktu'),
            'province' => $fallback('propinsi'),
            'tarif_percentage' => $fallback('tarif'),
            'ijp_amount' => $fallback('ijpAmount'),
            'administrative_amount' => $fallback('administrativeAmount'),
            'stamp_amount' => $fallback('stampAmount'),

            'updated_at' => $nowJakarta,
        ]);
        $this->handleAttachments($penjaminanPayload, $trxNo, $lampiranExist);
        DB::commit();
        return [
            'success' => true,
            'message' => 'Penjaminan Surety Bond successfully updated.'
        ];
    }

    private function handleAttachments($penjaminanPayload, $trxNo, $lampiranExist)
    {
        if (!$lampiranExist) return;

        $lampiranDtlData = PenjaminanLampiranDtl::where('trx_no', $trxNo)
            ->select('lampiran_id', DB::raw('MAX(version) as version'))
            ->groupBy('lampiran_id')
            ->get()
            ->toArray();

        $savedAttachments = [];

        foreach ($penjaminanPayload['attachments'] as $lampiranEdit) {
            $ext = $lampiranEdit['file']->getClientOriginalExtension();
            $unique = uniqid();

            $fn = "{$trxNo}-{$lampiranEdit['lampiran_id']}-cstb-{$unique}";

            $path = $lampiranEdit['file']->storeAs(
                'uploads/penjaminan/custom-bond',
                $fn . '.' . $ext,
                's3'
            );

            $searchResult = array_search(
                $lampiranEdit['lampiran_id'],
                array_column($lampiranDtlData, 'lampiran_id')
            );

            $newVersion = is_numeric($searchResult)
                ? $lampiranDtlData[$searchResult]['version'] + 1
                : 1;

            $file_path = json_encode([
                "path" => $path,
                "file_type" => $lampiranEdit['file']->getMimeType()
            ]);

            $savedAttachments[] = [
                'trx_no' => $trxNo,
                'lampiran_id' => $lampiranEdit['lampiran_id'],
                'file_name' => $fn,
                'status_doc' => 'N',
                'version' => $newVersion,
                'mime_type' => $lampiranEdit['file']->getMimeType(),
                'file_info' => $file_path
            ];
        }

        foreach ($savedAttachments as $item) {
            PenjaminanLampiranDtl::create($item);
        }
    }

    public function processUploadPembayaranManual($request)
    {
        $userData = auth('sanctum')->user();

        if (
            !json_validate($request->selected_items) ||
            !is_array(json_decode($request->selected_items))
        ) {
            throw new \Exception('Invalid selected item data.', 422);
        }

        $parsedItem = json_decode($request->selected_items);

        $arrinvoiceNumberValidation = collect($parsedItem)
            ->pluck('invoice_number')
            ->toArray();

        $duplicateInvoiceNo = count($arrinvoiceNumberValidation) != count(array_unique($arrinvoiceNumberValidation));

        if ($duplicateInvoiceNo) {
            throw new \Exception('Duplicate invoice data.', 422);
        }

        DB::beginTransaction();

        try {
            $cstbHeader = PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->where('trx_status', 'WFP')
                ->select('no_surat_permohonan')
                ->first();

            $tenorData = CustomBondTenorSchedule::query()
                ->from('custom_bond_transaction as cbt')
                ->join('custombond_tenor_schedule as cbs', 'cbt.id_bond', 'cbs.id_bond')
                ->select([
                    'cbs.cstb_schedule_id',
                    'cbt.id_bond',
                    'cbt.trx_no',
                    'cbs.tenor_sequence',
                    'cbs.due_date',
                    'cbs.invoice_number',
                    'cbs.amount',
                    'cbs.status'
                ])
                ->where('cbs.status', 'Pending')
                ->whereIn('cbs.invoice_number', $arrinvoiceNumberValidation)
                ->where('cbt.trx_no', $request->trx_no)
                ->orderBy('cbs.cstb_schedule_id')
                ->get();

            if ($tenorData->count() < 1 || !$cstbHeader) {
                throw new \Exception('Penjaminan Custom Bond not found or no payment data.', 404);
            }

            $amountSum = $tenorData->sum('amount');

            if ($amountSum != $request->amount) {
                throw new \Exception('Incorrect amount.', 400);
            }

            $noSuratPermohonan = $cstbHeader->no_surat_permohonan;
            $tenorSequence = $tenorData->pluck('tenor_sequence')[0];
            $paymentScope = $tenorSequence == 0 ? "Full Payment" : "Split Payment";

            $debiturPayload = [];
            $orderIdList = [];

            foreach ($tenorData as $tenorRow) {
                $debiturPayload[] = [
                    'no_sp_detail' => $noSuratPermohonan,
                    'invoice_number' => $tenorRow->invoice_number,
                    'total_amount' => (int) $tenorRow->amount
                ];

                $newInvoiceData = TrxCstbInvoiceHeader::create([
                    'cstb_schedule_id' => $tenorRow->cstb_schedule_id,
                    'invoice_scope' => $paymentScope,
                    'total_amount' => $tenorRow->amount,
                    'status' => 'Paid',
                    'is_manual' => 1,
                    'tenor_sequence' => $tenorRow->tenor_sequence &&
                        $tenorRow->tenor_sequence != 0
                        ? $tenorRow->tenor_sequence : null
                ]);

                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

                TrxCstbPaymentGateway::create([
                    'cstb_invoice_id' => $newInvoiceData->cstb_invoice_id,
                    'payment_amount_ijp' => $tenorRow->amount,
                    'status' => 'Paid',
                    'order_id' => $orderId
                ]);

                CustomBondTenorSchedule::where('cstb_schedule_id', $tenorRow->cstb_schedule_id)
                    ->update(['status' => 'Paid']);

                $orderIdList[] = $orderId;
            }

            $attachmentBuktiBayar = $request->file('file');
            $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));
            $ext = $attachmentBuktiBayar->getClientOriginalExtension();
            $fn = $orderIdList[0] . '-pembayaran-cstb';

            $creatioPayload = [
                'NoSuratPermohonan' => $noSuratPermohonan,
                'ListDebitur' => $debiturPayload,
                'NamaFile' => $fn . '.' . $ext,
                'DataBase64' => $fileBase64
            ];

            $svcCreatio = new CreatioService();
            $response = $svcCreatio->request(
                'post',
                '/0/rest/PembayaranWebService/PembayaranManualV2',
                $creatioPayload
            );

            if ($response->status() !== 200) {
                throw new \Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);

            if ($bodyResponse['Success'] !== true) {
                throw new \Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
            }

            $localStackPath = $attachmentBuktiBayar->storeAs(
                'uploads/penjaminan/payment-custom-bond',
                $fn . '.' . $ext,
                's3'
            );

            PenjaminanLampiranDtl::create([
                'trx_no' => $request->trx_no,
                'lampiran_id' => 'pembayaran',
                'file_name' => $fn,
                'status_doc' => 'N',
                'version' => 1,
                'mime_type' => $attachmentBuktiBayar->getMimeType(),
                'file_info' => $localStackPath
            ]);

            $nowJakarta = Carbon::now('Asia/Jakarta');

            PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->update([
                    'trx_status' => 'PD',
                    'updated_at' => $nowJakarta
                ]);

            PenjaminanFlow::insert([
                'trx_no' => $request->trx_no,
                'trx_status' => 'PD',
                'created_at' => $nowJakarta,
                'updated_at' => $nowJakarta,
                'created_by_id' => $userData->user_id,
                'created_by_name' => $userData->name
            ]);

            DB::commit();

            return 'Bukti bayar manual uploaded successfully.';
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function processSubmitDraft($request, $trxNo)
    {
        $user = auth('sanctum')->user();

        $penjaminanPayload = collect($request->data)->toArray();

        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }

        $lampiranExist = array_key_exists('lampiranEdit', $penjaminanPayload) &&
            $penjaminanPayload['lampiranEdit'] ? true : false;

        $duplicateLampiranId = false;

        if ($lampiranExist) {
            $lampiranIdMap = array_map(function ($mapItem) {
                return $mapItem['lampiran_id'];
            }, $penjaminanPayload['lampiranEdit']);

            $duplicateLampiranId = count(array_unique($lampiranIdMap)) != count($lampiranIdMap);
        }

        if ($duplicateLampiranId) {
            throw new \Exception('Duplicate lampiran id.', 422);
        }

        $isBastPenjaminan = $penjaminanPayload['isBast'];

        if ($isBastPenjaminan == true) {
            validator($request->all(), [
                'data.noSuratBast' => 'required|string|max:50',
                'data.tglSuratBast' => 'required|date'
            ])->validate();
        }

        DB::beginTransaction();

        try {
            $penjaminanTrxHeaderData = PenjaminanTransaction::where('trx_no', $trxNo)
                ->select('trx_no', 'trx_status')->first();

            $customBondData = CustomBondTransaction::where('trx_no', $trxNo)
                ->select('id_bond')->first();

            if (
                $penjaminanTrxHeaderData && $customBondData &&
                $penjaminanTrxHeaderData->trx_status == 'D'
            ) {
                $nowJakarta = Carbon::now('Asia/Jakarta');

                $submitTransactionPayload = [
                    'no_surat_permohonan' => $penjaminanPayload['noSuratPermohonan'],
                    'tanggal_surat_permohonan' => $penjaminanPayload['tglSuratPermohonan'],
                    'trx_status' => 'NA',
                    'sp_split' => $penjaminanPayload['isSplit'],
                    'updated_by_id' => $user->user_id,
                    'updated_by_name' => $user->name,
                    'updated_at' => $nowJakarta
                ];

                PenjaminanTransaction::where('trx_no', $trxNo)
                    ->update($submitTransactionPayload);

                $submitCustomBondPayload = [
                    'jenis_bond' => $penjaminanPayload['jenisBond'],
                    'jenis_bond_description' => $penjaminanPayload['descriptionBond'] ?? 'Custom Bond',
                    'principal_name' => $penjaminanPayload['namaPrincipal'],
                    'is_deposit' => $penjaminanPayload['isDeposit'],
                    'obligee_name' => $penjaminanPayload['namaObligee'],
                    'jenis_persyaratan' => $penjaminanPayload['jenisPernyataan'],
                    'skema_penalty' => $penjaminanPayload['skemaPenalty'],
                    'sektor' => $penjaminanPayload['sektor'],
                    'document_name' => $penjaminanPayload['documentName'] ?? null,
                    'document_number' => $penjaminanPayload['documentNumber'] ?? null,
                    'document_date' => isset($penjaminanPayload['documentDate'])
                        ? Carbon::parse($penjaminanPayload['documentDate'])->format('Y-m-d')
                        : null,
                    'is_bast' => $penjaminanPayload['isBast'],
                    'no_surat_bast' => $penjaminanPayload['isBast'] == true
                        ? $penjaminanPayload['noSuratBast']
                        : null,
                    'bast_date' => $penjaminanPayload['isBast'] == true
                        ? Carbon::parse($penjaminanPayload['tglSuratBast'])->format('Y-m-d')
                        : null,
                    'project_name' => $penjaminanPayload['namaProyek'],
                    'project_amount' => $penjaminanPayload['nilaiProyek'],
                    'amount_bond' => $penjaminanPayload['nilaiBond'],
                    'bond_percentage' => $penjaminanPayload['nilaiBondPersentase'],
                    'jenis_surat_perjanjian' => $penjaminanPayload['jenisSuratPerjanjian'],
                    'no_surat_perjanjian' => $penjaminanPayload['noSuratPerjanjian'],
                    'tgl_surat_perjanjian' => Carbon::parse($penjaminanPayload['tglSuratPerjanjian'])->format('Y-m-d'),
                    'start_period_date' => Carbon::parse($penjaminanPayload['periodeAwalBerlaku'])->format('Y-m-d'),
                    'end_period_date' => Carbon::parse($penjaminanPayload['periodeAkhirBerlaku'])->format('Y-m-d'),
                    'total_day' => $penjaminanPayload['jangkaWaktu'],
                    'province' => $penjaminanPayload['propinsi'],
                    'tarif_percentage' => array_key_exists('tarif', $penjaminanPayload) ? $penjaminanPayload['tarif'] : null,
                    'ijp_amount' => array_key_exists('ijpAmount', $penjaminanPayload) ? $penjaminanPayload['ijpAmount'] : null,
                    'administrative_amount' => array_key_exists('administrativeAmount', $penjaminanPayload) ? $penjaminanPayload['administrativeAmount'] : null,
                    'stamp_amount' => array_key_exists('stampAmount', $penjaminanPayload) ? $penjaminanPayload['stampAmount'] : null,
                    'updated_at' => $nowJakarta,
                ];

                CustomBondTransaction::where('trx_no', $trxNo)
                    ->update($submitCustomBondPayload);

                $savedAttachments = [];

                if ($lampiranExist) {
                    $lampiranDtlData = PenjaminanLampiranDtl::where('trx_no', $trxNo)
                        ->select('lampiran_id', DB::raw('MAX(version) as version'))
                        ->groupBy('lampiran_id')
                        ->get();

                    $collectLampiranDtl = collect($lampiranDtlData)->toArray();

                    foreach ($penjaminanPayload['lampiranEdit'] as $lampiranEdit) {
                        $ext = $lampiranEdit['file']->getClientOriginalExtension();
                        $unique = uniqid();
                        $fn = "{$trxNo}-{$lampiranEdit['lampiran_id']}-cstb-{$unique}";

                        $path = $lampiranEdit['file']->storeAs(
                            'uploads/penjaminan/custom-bond',
                            $fn . '.' . $ext,
                            's3'
                        );

                        $searchResult = array_search(
                            $lampiranEdit['lampiran_id'],
                            array_column($collectLampiranDtl, 'lampiran_id')
                        );

                        $newDocumentVersion = is_numeric($searchResult)
                            ? $collectLampiranDtl[$searchResult]['version'] + 1
                            : 1;

                        $file_path = json_encode([
                            "path" => $path,
                            "file_type" => $lampiranEdit['file']->getMimeType()
                        ]);

                        $savedAttachments[] = [
                            'trx_no' => $trxNo,
                            'lampiran_id' => $lampiranEdit['lampiran_id'],
                            'file_name' => $fn,
                            'status_doc' => 'N',
                            'version' => $newDocumentVersion,
                            'mime_type' => $lampiranEdit['file']->getMimeType(),
                            'file_info' => $file_path
                        ];
                    }
                }

                if (!empty($savedAttachments)) {
                    foreach ($savedAttachments as $saveAtt) {
                        PenjaminanLampiranDtl::create($saveAtt);
                    }
                }

                PenjaminanFlow::create([
                    'trx_no' => $trxNo,
                    'trx_status' => 'NA',
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Penjaminan Custom Bond successfully submitted.'
                ]);
            } else if ($penjaminanTrxHeaderData && $customBondData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data is not draft.'
                ], 422);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan custom bond data is not found.'
                ], 400);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
