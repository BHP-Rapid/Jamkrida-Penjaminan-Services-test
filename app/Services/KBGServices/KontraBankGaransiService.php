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

    private function getDetailKbgOrFail(string $trx_no)
    {
        $data = $this->repository->getTrxKbgDetail($trx_no);
        if(!$data)
        {
            throw new NotFoundException('Penjaminan data is not found.');
        }
        return $data;
    }

    private function storeAttachments(array $attachments)
    {
        if(!empty($attachments))
        {
            $this->repository->insertAttachmentsKbg($attachments);
        }
    }
}
