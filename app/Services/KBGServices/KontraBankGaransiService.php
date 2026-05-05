<?php

namespace App\Services\KBGServices;

use App\Exceptions\NotFoundException;
use App\Helpers\AesHelper;
use App\Repositories\KontraBankGaransiRepository;
use App\Services\InstitutionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KontraBankGaransiService
{
    public function __construct(
        protected KontraBankGaransiRepository $repository
    ) {

    }

    public function kbgStore(Request $request, $user)
    {
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        $mitraAlias = $mitraData->alias;
        $penjaminanPayload = collect($request->data)->toArray();
        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }
        $hasLampiran = array_key_exists('lampiran', $penjaminanPayload)
            ? KBGValidate::checkDuplicateLampiran($penjaminanPayload['lampiran'])
            : false;
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
        $institutionCollect = collect($request->data['institution_data']);
        $institutionPayload = $institutionCollect->only($institutionKeys)->toArray();
        $institutionPayload['category'] = 'P';
        $institutionPayload['id_issued_location'] = '-';
        $institutionPayload['phone_type'] = '-';
        $institutionPayload['tenant_id'] = $mitraData->tenant_id;
        $institutionPayload['mitra_id'] = $mitraAlias;


        $institutionService->insertInstitution($institutionPayload, $user->user_id);
        $institutionGuidNew = $institutionService->getCreatedInstitutionId();

        $institutionIdList = [$institutionGuidNew];
        try
        {
            DB::transaction(function () use (
                $penjaminanPayload,
                $mitraAlias,
                $hasLampiran,
                $institutionGuidNew,
                $user
            ) {
                $trxInsertStatus = $penjaminanPayload['status'] == 'submit' ? 'NA' : 'D';
                $idInstitution = DB::table('institution')
                    ->where('institution_id', $institutionGuidNew)
                    ->select('id')->first();
                $idInstitution = $this->repository->getIdInstitution($institutionGuidNew);
                $currentYear = date('Y');
                $currentMonth = date('m');
                $lastTrx = $this->repository->getLastTrxNo($currentYear, $currentMonth);
                if ($lastTrx) {
                    $lastSequence = intval(substr($lastTrx, -4));
                    $nextSeq = $lastSequence + 1;
                } else {
                    $nextSeq = 1;
                }
                $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
                $nowJakarta = Carbon::now('Asia/Jakarta');

                $fallback = function (string $key, $default = null) use ($penjaminanPayload) {
                    if (array_key_exists($key, $penjaminanPayload) && $penjaminanPayload[$key] != null) {
                        return $penjaminanPayload[$key];
                    }
                    return $default;
                };
                $headerPayload = KBGGeneratePayload::generateHeaderKBG($trxNo, $mitraAlias, $trxInsertStatus, $penjaminanPayload, $user);
                $this->repository->insertHeaderKbg($headerPayload);

                $insertKbgPayload = [
                    'trx_no' => $trxNo,
                    'jenis_garansi' => $penjaminanPayload['jenisGaransi'],
                    'jenis_garansi_description' => $penjaminanPayload['jenisGaransiDescription'] ?? null,
                    'jenis_persyaratan' => $fallback('jenisPersyaratan'),
                    'skema_penalty' => $fallback('skemaPenalty'),
                    'sektor' => $fallback('sektor'),
                    'principal_name' => $fallback('namaPrincipal'),
                    'obligee_name' => $fallback('namaObligee'),
                    'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
                    'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
                    'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
                    'bank_code' => $fallback('namaBank'),
                    'bank_name' => $fallback('bankCabang'),
                    'id_institution' => $idInstitution->id,
                    'is_bast' => $fallback('isBast'),
                    'no_surat_bast' => array_key_exists('isBast', $penjaminanPayload) &&
                        $penjaminanPayload['isBast'] == true ?
                        $fallback('noSuratBast') : null,
                    'bast_date' => array_key_exists('isBast', $penjaminanPayload) &&
                        $penjaminanPayload['isBast'] == true ?
                        $fallback('tglSuratBast') : null,
                    'project_name' => $fallback('namaProyek'),
                    'project_amount' => $fallback('nilaiProyek'),
                    'amount_garansi' => $fallback('nilaiGaransi'),
                    'garansi_percentage' => $fallback('nilaiGaransiPersentase'),
                    'start_period_date' => $fallback('periodeAwalBerlaku'),
                    'end_period_date' => $fallback('periodeAkhirBerlaku'),
                    'total_day' => $fallback('jangkaWaktu'),
                    'province' => $fallback('provinsi'),
                    'agunan_amount' => $fallback('nilaiAgunan'),
                ];
                $this->repository->insertTrxKbg($insertKbgPayload);

                $savedAttachments = [];
                // if ($hasLampiran) {
                //     foreach ($penjaminanPayload['lampiran'] as $lampiranItem) {
                //         $ext = $lampiranItem['file']->getClientOriginalExtension();
                //         $unique = uniqid();
                //         $fn = "{$trxNo}-{$lampiranItem['lampiran_id']}-kbg-{$unique}";
                //         $path = $lampiranItem['file']->storeAs(
                //             'uploads/penjaminan/kbg',
                //             $fn . '.' . $ext,
                //             's3'
                //         );
                //         $savedAttachments[] = [
                //             'trx_no' => $trxNo,
                //             'lampiran_id' => $lampiranItem['lampiran_id'],
                //             'file_name' => $fn,
                //             'status_doc' => 'N',
                //             'version' => 1,
                //             'mime_type' => $lampiranItem['file']->getMimeType(),
                //             'file_info' => $path,
                //             'created_at' => $nowJakarta
                //         ];
                //     }
                // }
                // $this->storeAttachments($savedAttachments);
                $this->repository->insertPenjaminanKbgFlow($trxNo, $trxInsertStatus, $user);
            });
        } catch(Exception $ex) {
            $this->deleteInstitutionData($institutionIdList);
            throw new Exception('Failed to insert penjaminan KBG (' . $ex->getMessage() . ')', 0, $ex);
        }
    }

    public function kbgDraftUpdate(string $trx_no, Request $request, object $user)
    {
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        $penjaminanPayload = collect($request->data)->toArray();
        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }
        $hasLampiran = array_key_exists('lampiranEdit', $penjaminanPayload)
            ? KBGValidate::checkDuplicateLampiran($penjaminanPayload['lampiranEdit'], 'data.lampiranEdit')
            : false;
        $kbgStatus = $this->getKbgStatusDraftExistsOrFail($trx_no);
        if($kbgStatus != 'D')
        {
            return [
                'success' => false,
                'message' => 'Data is not draft.',
                'code' => 400
            ];
        }
        return DB::transaction(function () use ($trx_no, $penjaminanPayload, $hasLampiran, $user) {
            $fallback = function (string $key, $default = null) use ($penjaminanPayload) {
                if (array_key_exists($key, $penjaminanPayload)) {
                    return $penjaminanPayload[$key];
                }
                return $default;
            };
            $headerUpdate = KBGGeneratePayload::generateHeaderUpdateKBG($penjaminanPayload, $user);
            $this->repository->updateHeaderKbgDraft($trx_no, $headerUpdate);
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $kbgUpdatePayload = [
                // 'jenis_bond' => $fallback('jenisBond'),
                'jenis_garansi' => $fallback('jenisGaransi'),
                'jenis_persyaratan' => $fallback('jenisPernyataan'),
                'skema_penalty' => $fallback('skemaPenalty'),
                'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
                'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
                'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
                'bank_code' => $fallback('namaBank'),
                'bank_name' => $fallback('bankCabang'),
                'sektor' => $fallback('sektor'),
                'principal_name' => $fallback('namaPrincipal'),
                'obligee_name' => $fallback('namaObligee'),
                'is_bast' => $fallback('isBast'),
                'no_surat_bast' => array_key_exists('isBast', $penjaminanPayload) &&
                    $penjaminanPayload['isBast'] == true ?
                    $fallback('noSuratBast') : null,
                'bast_date' => array_key_exists('isBast', $penjaminanPayload) &&
                    $penjaminanPayload['isBast'] == true ?
                    $fallback('tglSuratBast') : null,
                'project_name' => $fallback('namaProyek'),
                'project_amount' => $fallback('nilaiProyek'),
                // 'amount_bond' => $fallback('nilaiBond'),
                // 'bond_percentage' => $fallback('nilaiBondPersentase'),
                'amount_garansi' => $fallback('nilaiGaransi'),
                'garansi_percentage' => $fallback('nilaiGaransiPersentase'),
                'start_period_date' => $fallback('periodeAwalBerlaku'),
                'end_period_date' => $fallback('periodeAkhirBerlaku'),
                'total_day' => $fallback('jangkaWaktu'),
                'province' => $fallback('provinsi'),
                // 'tarif_percentage' => $fallback('tarif'),
                'updated_at' => $nowJakarta,
                // 'ijp_amount' => $fallback('ijpAmount'),
                'agunan_amount' => $fallback('nilaiAgunan'),
                // 'stamp_amount' => $fallback('stampAmount')
            ];
            $this->repository->updateTrxKbg($trx_no, $kbgUpdatePayload);
            $savedAttachments = [];
            // if($hasLampiran)
            // {
            //     $lampiranDtlData = $this->repository->getPenjaminanLampiranLatestVersionList($trx_no);
            //     $collectLampiranDtl = collect($lampiranDtlData)->toArray();
            //     foreach ($penjaminanPayload['lampiranEdit'] as $lampiranEdit) {
            //         $ext = $lampiranEdit['file']->getClientOriginalExtension();
            //             $unique = uniqid();
            //             $fn = "{$trx_no}-{$lampiranEdit['lampiran_id']}-srtb-{$unique}";
            //             $path = $lampiranEdit['file']->storeAs(
            //                 'uploads/penjaminan/surety-bond',
            //                 $fn . '.' . $ext,
            //                 's3'
            //             );
            //             $newDocumentVersion = 1;
            //             $searchResult = array_search(
            //                 $lampiranEdit['lampiran_id'],
            //                 array_column($collectLampiranDtl, 'lampiran_id')
            //             );
            //             $newDocumentVersion = is_numeric($searchResult) ?
            //                 $collectLampiranDtl[$searchResult]['version'] + 1 : 1;
            //             $savedAttachments[] = [
            //                 'trx_no' => $trxNo,
            //                 'lampiran_id' => $lampiranEdit['lampiran_id'],
            //                 'file_name' => $fn,
            //                 'status_doc' => 'N',
            //                 'version' => $newDocumentVersion,
            //                 'mime_type' => $lampiranEdit['file']->getMimeType(),
            //                 'file_info' => $path
            //             ];
            //     }
            // }
            // $this->storeAttachments($savedAttachments);
            return [
                'success' => true
            ];
        });
    }

    public function kbgSubmitDraft(string $trx_no, Request $request, object $user)
    {
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        $penjaminanPayload = collect($request->data)->toArray();
        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }
        $hasLampiran = array_key_exists('lampiranEdit', $penjaminanPayload)
            ? KBGValidate::checkDuplicateLampiran($penjaminanPayload['lampiranEdit'], 'data.lampiranEdit')
            : false;
        $kbgStatus = $this->getKbgStatusDraftExistsOrFail($trx_no);
        if($kbgStatus != 'D')
        {
            return [
                'success' => false,
                'message' => 'Data is not draft.',
                'code' => 400
            ];
        }
        return DB::transaction(function () use ($trx_no, $penjaminanPayload, $hasLampiran, $user) {
            $fallback = function (string $key, $default = null) use ($penjaminanPayload) {
                if (array_key_exists($key, $penjaminanPayload)) {
                    return $penjaminanPayload[$key];
                }
                return $default;
            };
            $headerUpdate = KBGGeneratePayload::generateHeaderUpdateKBG($penjaminanPayload, $user, true);
            // dd($headerUpdate);
            $this->repository->updateHeaderKbgDraft($trx_no, $headerUpdate);
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $kbgUpdatePayload = [
                // 'jenis_bond' => $fallback('jenisBond'),
                'jenis_garansi' => $fallback('jenisGaransi'),
                'jenis_persyaratan' => $fallback('jenisPernyataan'),
                'skema_penalty' => $fallback('skemaPenalty'),
                'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
                'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
                'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
                'bank_code' => $fallback('namaBank'),
                'bank_name' => $fallback('bankCabang'),
                'sektor' => $fallback('sektor'),
                'principal_name' => $fallback('namaPrincipal'),
                'obligee_name' => $fallback('namaObligee'),
                'is_bast' => $fallback('isBast'),
                'no_surat_bast' => array_key_exists('isBast', $penjaminanPayload) &&
                    $penjaminanPayload['isBast'] == true ?
                    $fallback('noSuratBast') : null,
                'bast_date' => array_key_exists('isBast', $penjaminanPayload) &&
                    $penjaminanPayload['isBast'] == true ?
                    $fallback('tglSuratBast') : null,
                'project_name' => $fallback('namaProyek'),
                'project_amount' => $fallback('nilaiProyek'),
                // 'amount_bond' => $fallback('nilaiBond'),
                // 'bond_percentage' => $fallback('nilaiBondPersentase'),
                'amount_garansi' => $fallback('nilaiGaransi'),
                'garansi_percentage' => $fallback('nilaiGaransiPersentase'),
                'start_period_date' => $fallback('periodeAwalBerlaku'),
                'end_period_date' => $fallback('periodeAkhirBerlaku'),
                'total_day' => $fallback('jangkaWaktu'),
                'province' => $fallback('provinsi'),
                // 'tarif_percentage' => $fallback('tarif'),
                'updated_at' => $nowJakarta,
                // 'ijp_amount' => $fallback('ijpAmount'),
                'agunan_amount' => $fallback('nilaiAgunan'),
                // 'stamp_amount' => $fallback('stampAmount')
            ];
            $this->repository->updateTrxKbg($trx_no, $kbgUpdatePayload);
            $savedAttachments = [];
            // if($hasLampiran)
            // {
            //     $lampiranDtlData = $this->repository->getPenjaminanLampiranLatestVersionList($trx_no);
            //     $collectLampiranDtl = collect($lampiranDtlData)->toArray();
            //     foreach ($penjaminanPayload['lampiranEdit'] as $lampiranEdit) {
            //         $ext = $lampiranEdit['file']->getClientOriginalExtension();
            //             $unique = uniqid();
            //             $fn = "{$trx_no}-{$lampiranEdit['lampiran_id']}-srtb-{$unique}";
            //             $path = $lampiranEdit['file']->storeAs(
            //                 'uploads/penjaminan/surety-bond',
            //                 $fn . '.' . $ext,
            //                 's3'
            //             );
            //             $newDocumentVersion = 1;
            //             $searchResult = array_search(
            //                 $lampiranEdit['lampiran_id'],
            //                 array_column($collectLampiranDtl, 'lampiran_id')
            //             );
            //             $newDocumentVersion = is_numeric($searchResult) ?
            //                 $collectLampiranDtl[$searchResult]['version'] + 1 : 1;
            //             $savedAttachments[] = [
            //                 'trx_no' => $trxNo,
            //                 'lampiran_id' => $lampiranEdit['lampiran_id'],
            //                 'file_name' => $fn,
            //                 'status_doc' => 'N',
            //                 'version' => $newDocumentVersion,
            //                 'mime_type' => $lampiranEdit['file']->getMimeType(),
            //                 'file_info' => $path
            //             ];
            //     }
            // }
            // $this->storeAttachments($savedAttachments);
            $this->repository->insertPenjaminanKbgFlow($trx_no, 'NA', $user);
            return [
                'success' => true
            ];
        });
        
    }

    public function pembayaranManualKbg(Request $request, object $user)
    {
        $itemValidation = KBGValidate::validateWithReturnManualPay($request->selected_items);
        if(!$itemValidation['success']) {
            return $itemValidation;
        }
        $invoiceNo = $itemValidation['data'][0];
        return DB::transaction(function () use ($invoiceNo, $request, $user) {
            $trxNo = $request->trx_no;
            $kbgHeader = $this->getSuratPermohonanKbgOrFail($trxNo);
            $tenorData = $this->getTenorKbgOrFail($trxNo, $invoiceNo);
            $amountSum = $tenorData->sum('amount');
            if($amountSum != $request->amount) {
                return [
                    'success' => false,
                    'message' => 'Incorrect amount.'
                ];
            }
            $noSuratPermohonan = $kbgHeader->no_surat_permohonan;
            $headerPaymentList = [];
            $debiturPayload = [];
            $orderIdList = [];
            foreach($tenorData as $tenorRow) {
                $paymentScope = $tenorRow->tenor_sequence == 0
                    ? 'Full Payment' : 'Split Payment';
                $debiturPayload[] = [
                    'no_sp_detail' => $noSuratPermohonan,
                    'invoice_number' => $tenorRow->invoice_number,
                    'total_amount' => (int)$tenorRow->amount
                ];
                $headerPaymentList[] = [
                    'kbg_schedule_id' => $tenorRow->kbg_schedule_id,
                    'id_trx_product' => $tenorRow->id_trx_product,
                    'trx_no' => $tenorRow->trx_no,
                    'tenor_sequence' => $tenorRow->tenor_sequence,
                    'invoice_number' => $tenorRow->invoice_number,
                    'amount' => $tenorRow->amount,
                    'invoice_scope' => $paymentScope,
                ];
            }
            foreach($headerPaymentList as $headerPayment){
                $newInvoiceData = $this->repository->insertInvoiceHeaderManual(
                    $headerPayment,
                    'Paid'
                );
                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
                $this->repository->insertPaymentGatewayManual(
                    $newInvoiceData->kbg_invoice_id,
                    $orderId,
                    $headerPayment['amount']
                );
                $this->repository->updateTenorDataByScheduleId(
                    $headerPayment['kbg_schedule_id'],
                    [
                        'status' => 'Paid'
                    ]
                );
                $orderIdList[] = $orderId;
            }
            $attachmentBuktiBayar = $request->file('file');
            $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));
            $ext = $attachmentBuktiBayar->getClientOriginalExtension();
            $fn = $orderIdList[0] . '-pembayaran-kbg';
            $creatioPayload = [
                'NoSuratPermohonan' => $noSuratPermohonan,
                'ListDebitur' => $debiturPayload,
                'NamaFile' => $fn . '.' . $ext,
                'DataBase64' => $fileBase64
            ];
            // PENDING SEND TO CORE
            // $svcCreatio = new CreatioService();
            // $response = $svcCreatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', $creatioPayload);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
            // }
            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
            // }

            // Storing file bukti bayar to LocalStack and PenjaminanLampiranDtl table
            // $localStackPath = $attachmentBuktiBayar->storeAs(
            //     'uploads/penjaminan/payment-surety-bond',
            //     $fn . '.' . $ext,
            //     's3'
            // );
            // $this->storeAttachments([
            //     'trx_no' => $request->trx_no,
            //     'lampiran_id' => 'pembayaran',
            //     'file_name' => $fn,
            //     'status_doc' => 'N',
            //     'version' => 1,
            //     'mime_type' => $attachmentBuktiBayar->getMimeType(),
            //     'file_info' => $localStackPath
            // ]);
            return [
                'success' => true
            ];
        });
    }

    public function deleteInstitutionData(array $institution_id)
    {
        $this->repository->deleteInstitutionData($institution_id);
    }

    public function getDetailPenjaminanKbg(string $trx_no, object $user)
    {
        $mitraData = $this->getTenantDataOrFail($user->mitra_id);
        $mitraAlias = $mitraData->alias;
        $penjaminanData = $this->getDetailKbgOrFail($trx_no);
        $key = base64_decode(config('services.secure.key'));
        $institutionData = $this->repository->getPersonalInstitution($penjaminanData->id_institution);
        if($institutionData) {
            $institutionData->phone_1 = !empty($institutionData->phone_1)
                ? AesHelper::decrypt($institutionData->phone_1, $key)
                : null;
            $institutionData->email_1 = !empty($institutionData->email_1)
                ? AesHelper::decrypt($institutionData->email_1, $key)
                : null;
            $institutionData->birth_date = !empty($institutionData->birth_date)
                ? AesHelper::decrypt($institutionData->birth_date, $key)
                : null;
            $institutionData->id_number = !empty($institutionData->id_number)
                ? AesHelper::decrypt($institutionData->id_number, $key)
                : null;
            $institutionData->tax_id = !empty($institutionData->tax_id)
                ? AesHelper::decrypt($institutionData->tax_id, $key)
                : null;
            $institutionData->current_salary_amount = !empty($institutionData->current_salary_amount)
                ? AesHelper::decrypt($institutionData->current_salary_amount, $key)
                : null;
            $institutionData->other_income_amount = !empty($institutionData->other_income_amount)
                ? AesHelper::decrypt($institutionData->other_income_amount, $key)
                : null;
        }
        $penjaminanData->institution = $institutionData;
        $docList = $this->repository->getPenjaminanLampiranDetail($trx_no, $mitraAlias);
        $lampiranDtl = array_map(function ($item) {
                $detailFound = false;
                $fileUrl = null;
                $filePath = "";
                if($item->lampiran_id != null) {
                    $decodedInfo = json_validate($item->file_info) ?
                        json_decode($item->file_info) : null;
                    $filePath = $decodedInfo != null && $decodedInfo->path ?
                        $decodedInfo->path : $item->file_info;
                    // $fileUrl = Storage::disk('s3')->temporaryUrl(
                    //     $filePath,
                    //     now()->addMinutes(15)
                    // );
                }
                $result = [
                    'key_lampiran' => $item->value,
                    'label_lampiran' => $item->label,
                    'option_type' => $item->option2,
                    'file_name' => $item->file_name,
                    'file_path' => $filePath,
                    'is_additional' => $item->is_additional,
                    'status_doc' => $item->status_doc,
                    'mime_type' => $item->mime_type,
                    'presigned_url' => $fileUrl
                ];
                return $result;
            }, $docList);
        $penjaminanData->lampiran = $lampiranDtl;
        return [
            'success' => true,
            'data' => $penjaminanData
        ];
    }

    private function getTenantDataOrFail(string $mitra_id)
    {
        $tenantData = $this->repository->getTenantMitraData($mitra_id);
        if(!$tenantData)
        {
            throw new NotFoundException('Tenant mitra data is not found.');
        }
        return $tenantData;
    }

    private function getSuratPermohonanKbgOrFail(string $trx_no)
    {
        $data = $this->repository->getSuratPermohonanKbg($trx_no);
        if(!$data) {
            throw new NotFoundException('Penjaminan Kontra Bank Garansi not found.');
        }
        return $data;
    }

    private function getTenorKbgOrFail(string $trx_no, string $invoice_no)
    {
        $tenor = $this->repository->getTenorDataKbg($trx_no, $invoice_no);
        if(count($tenor) < 1)
        {
            throw new NotFoundException('Payment data not found.');
        }
        return $tenor;
    }

    private function getDetailKbgOrFail(string $trx_no)
    {
        $data = $this->repository->getTrxKbgDetail($trx_no);
        if(!$data)
        {
            throw new NotFoundException('Penjaminan data is not found.');
        }
        return $data;
    }

    private function getKbgStatusDraftExistsOrFail(string $trx_no)
    {
        $header = $this->repository->getHeaderKbgStatus($trx_no);
        $trxProductId = $this->repository->getTrxKbgId($trx_no);
        if(!$header || !$trxProductId)
        {
            throw new NotFoundException('Penjaminan data is not found.');
        }
        return $header->trx_status;
    }

    private function storeAttachments(array $attachments)
    {
        if(!empty($attachments))
        {
            $this->repository->insertAttachmentsKbg($attachments);
        }
    }
}
