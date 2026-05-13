<?php

namespace App\Services\CustomBondServices;

use App\Exceptions\NotFoundException;
use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Models\CustomBondTenorSchedule;
use App\Models\CustomBondTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TrxCstbInvoiceHeader;
use App\Models\TrxCstbPaymentGateway;
use App\Repositories\CustomBondRepository;
use App\Services\CreatioService;
use App\Services\FileInternalClient;
use App\Services\InstitutionService;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomBond
{

    public function __construct(
        protected CustomBondRepository $repository,
        protected FileInternalClient $fileInternalClient
    ) {}

    public function getDetail(string $trx_no, string $no_surat_permohonan)
    {
        $key = base64_decode(config('services.secure.key'));
        $result = $this->repository->getDetail($trx_no, $no_surat_permohonan);
        if (!$result) {
            throw new NotFoundException('Custom Bond transaction not found.', null, 422);
        }
        $data = $result['data'];
        $institution = $result['institution'];
        if ($institution) {
            $this->decryptInstitution($institution, $key);
        }
        $lampiran = $this->getLampiran($trx_no);
        $data->setAttribute('lampiran', $lampiran);
        $data->institutionData = $institution;
        return $data;
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

    private function getLampiran(string $trx_no)
    {
        $lampiranData = $this->repository->getLampiranData($trx_no);
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

    public function store(array $payload, object $user)
    {
        $institutionIsInserted = false;
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        $mitraAlias = $mitraData->alias;
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

        return DB::transaction(function () use (
            $user,
            $trxInsertStatus,
            $institutionService,
            $penjaminanPayload,
            $mitraAlias
        ) {
            $this->handleTransaction(
                $user,
                $trxInsertStatus,
                $institutionService,
                $penjaminanPayload,
                $mitraAlias
            );
            return ApiResponse::success(null, 'Store Custom Bond has success');
        });
    }

    private function handleTransaction($user, $trxInsertStatus, $institutionService, $penjaminanPayload, $mitraAlias)
    {
        $idInstitution = $this->repository->getInstitutionId($institutionService->getCreatedInstitutionId());
        $currentYear = date('Y');
        $currentMonth = date('m');
        $lastTrx = $this->repository->getLastTrx($currentYear, $currentMonth);
        $nextSeq = $lastTrx ? intval(substr($lastTrx, -4)) + 1 : 1;
        $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
        $permohonanDate = Carbon::parse($penjaminanPayload['tglSuratPermohonan'])->format('Y-m-d');
        $nowJakarta = Carbon::now('Asia/Jakarta');

        $this->repository->insertPenjaminanTransaction([
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

        $this->repository->insertCustomBondTransaction([
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
                $this->repository->insertLampiran([
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

        $this->repository->insertFlow([
            'trx_no' => $trxNo,
            'trx_status' => $trxInsertStatus,
            'created_at' => $nowJakarta,
            'created_by_id' => $user->user_id,
            'created_by_name' => $user->name,
            'updated_at' => null
        ]);

        return $trxNo;
    }

    public function lampiranCustomBond($trx_no, $product, $lampiran)
    {
        try {
            $fn = $trx_no . "-" . $lampiran['lampiran_id'] . "-" . $product;
            $file = $lampiran['file'];
            $fileExisting = $this->repository->getLampiranByFileName($fn);
            $exist = $fileExisting ? json_decode($fileExisting->file_info, true) : null;
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

    public function updateDraft(array $payload, string $trxNo, object $user)
    {
        $penjaminanPayload = $payload['data'] ?? [];
        unset($penjaminanPayload['institution_data']);
        $isBastPenjaminan = $penjaminanPayload['isBast'] ?? false;
        if ($isBastPenjaminan) {
            if (empty($penjaminanPayload['noSuratBast']) || empty($penjaminanPayload['tglSuratBast'])) {
                throw new NotFoundException('No Surat BAST and Tanggal BAST are required when isBast = true', null, 422);
            }
        }
        $lampiranExist = !empty($penjaminanPayload['attachments'] ?? []);
        if ($lampiranExist) {
            $lampiranIdMap = array_map(
                fn($item) => $item['lampiran_id'],
                $penjaminanPayload['attachments']
            );
            if (count(array_unique($lampiranIdMap)) !== count($lampiranIdMap)) {
                throw new NotFoundException('Duplicate lampiran id.', null, 422);
            }
        }
        $fallback = function (string $key, $default = null) use ($penjaminanPayload) {
            return array_key_exists($key, $penjaminanPayload)
                ? $penjaminanPayload[$key]
                : $default;
        };

        return DB::transaction(function () use (
            $trxNo,
            $user,
            $fallback,
            $penjaminanPayload,
            $isBastPenjaminan,
            $lampiranExist
        ) {
            $data = $this->repository->getDraftData($trxNo);

            $penjaminanTrxHeaderData = $data['header'];
            $customBondData = $data['bond'];
            if (!$penjaminanTrxHeaderData || !$customBondData) {
                throw new NotFoundException('Penjaminan data is not found.', null, 404);
            }

            if ($penjaminanTrxHeaderData->trx_status !== 'D') {
                throw new NotFoundException('Data is not draft.', null, 422);
            }

            $nowJakarta = Carbon::now('Asia/Jakarta');
            $this->repository->updatePenjaminanTransaction($trxNo, [
                'no_surat_permohonan' => $fallback('noSuratPermohonan'),
                'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan'),
                'is_split' => $fallback('isSplit'),
                'updated_by_id' => $user->user_id,
                'updated_by_name' => $user->name,
                'updated_at' => $nowJakarta
            ]);
            $this->repository->updateCustomBondTransaction($trxNo, [
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

            return [
                'success' => true,
                'message' => 'Penjaminan Surety Bond successfully updated.'
            ];
        });
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
            $cstbHeader = $this->repository->getPaymentHeader($request->trx_no);
            $tenorData = $this->repository->getPendingTenorData(
                $request->trx_no,
                $arrinvoiceNumberValidation
            );
            if ($tenorData->count() < 1 || !$cstbHeader) {
                throw new NotFoundException('Penjaminan Custom Bond not found or no payment data.', null, 404);
            }
            $amountSum = $tenorData->sum('amount');
            if ($amountSum != $request->amount) {
                throw new NotFoundException('Incorrect amount.', null, 404);
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

    public function processSubmitDraft(array $payload, $trxNo)
    {
        $user = auth('sanctum')->user();

        $penjaminanPayload = $payload;

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
            throw new HttpException(422, 'Duplicate lampiran id.');
        }

        if ($penjaminanPayload['isBast'] == true) {
            if (
                empty($penjaminanPayload['noSuratBast']) ||
                empty($penjaminanPayload['tglSuratBast'])
            ) {
                throw new HttpException(
                    422,
                    'No Surat BAST and Tanggal BAST are required when isBast = true'
                );
            }
        }
        DB::transaction(function () use ($penjaminanPayload, $trxNo, $user, $lampiranExist) {
            $penjaminanTrxHeaderData = PenjaminanTransaction::where('trx_no', $trxNo)
                ->select('trx_no', 'trx_status')->first();
            $customBondData = CustomBondTransaction::where('trx_no', $trxNo)
                ->select('id_bond')->first();
            if ($penjaminanTrxHeaderData && $customBondData && $penjaminanTrxHeaderData->trx_status == 'D') {
                $nowJakarta = Carbon::now('Asia/Jakarta');
                PenjaminanTransaction::where('trx_no', $trxNo)->update([
                    'no_surat_permohonan' => $penjaminanPayload['noSuratPermohonan'],
                    'tanggal_surat_permohonan' => $penjaminanPayload['tglSuratPermohonan'],
                    'trx_status' => 'NA',
                    'sp_split' => $penjaminanPayload['isSplit'],
                    'updated_by_id' => $user->user_id,
                    'updated_by_name' => $user->name,
                    'updated_at' => $nowJakarta
                ]);
                CustomBondTransaction::where('trx_no', $trxNo)->update([
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
                ]);
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
            } else if ($penjaminanTrxHeaderData && $customBondData) {
                throw new NotFoundException('Data is not draft.', null, 422);
            } else {
                throw new NotFoundException('Penjaminan custom bond data is not found.', null, 404);
            }
        });
        return true;
    }


    public function getDetailPaymentCstb(array $payload)
    {
        $trx_no = $payload['trx_no'];
        $isSplit = $payload['is_split'];
        $key = $payload['key'];
        $no_surat_permohonan = $payload['no_surat_permohonan'];
        $resultPending = [];
        $dataPending = $this->repository->getDetailPaymentCstbPending($trx_no, $no_surat_permohonan, $isSplit);
        if ($dataPending) {
            $resultPending[] = [
                'schedule_id'    => $dataPending->cstb_schedule_id,
                'id_number'      => AesHelper::decrypt($dataPending->id_number, $key),
                'id_type'        => $dataPending->id_type,
                'full_name'      => $dataPending->full_name,
                'amount'         => $dataPending->amount,
                'invoice_number' => $dataPending->invoice_number,
                'due_date'       => $dataPending->due_date,
                'status'         => $dataPending->status,
                'tenor_sequence' => $isSplit ? $dataPending->tenor_sequence : 0,
            ];
        }
        $dataUnpaid = $this->repository->getDetailPaymentCstbUnpaid($trx_no, $no_surat_permohonan, $isSplit);
        return [
            'dataHeader' => [
                'data_pending' => $resultPending,
                'data_unpaid'  => $dataUnpaid
            ]
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

    private function getTenantDataOrFail(string $mitra_id)
    {
        $tenantData = $this->repository->getTenantMitraData($mitra_id);
        if (!$tenantData) {
            throw new NotFoundException('Tenant mitra data is not found.', null, 404);
        }
        return $tenantData;
    }
}
