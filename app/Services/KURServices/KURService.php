<?php

namespace App\Services\KURServices;

use App\Exceptions\NotFoundException;
use App\Helpers\ValidateDebitur;
use App\Helpers\AesHelper;
use App\Models\Institution;
use App\Models\KURTransaction;
use App\Models\NotifMitra;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanTransaction;
use App\Models\TrxDebiturDefaultBase;
use App\Repositories\KURRepository;
use App\Services\CreatioService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KURService
{
    public function __construct(
        protected KURRepository $repository
    ) {

    }

    public function kurStore(Request $request, $user)
    {
        // dd("E(o0o)3");
        $mitraData = $this->getTenantMitraDataOrFail($user->mitra_id);
        $mitraAlias = $mitraData->alias;
        $tenantId = $mitraData->tenant_id;
        // dd($mitraData);
        $this->validatePayloadByStatus($request);
        $penjaminanPKSResponse = $this->getPenjaminanPKS($mitraAlias);
        $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
        if(empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
            throw new Exception($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
        }
        $selectedPks = $request->data['selectedPks'];
        $dataDebitur = $request->input('data.dataDebitur', []);
        $result = KURValidate::validateDebiturBatch([
            'selectedPks' => $selectedPks,
            'penjaminanPKSData' => $penjaminanPKSData,
            'dataDebitur' => $dataDebitur
        ]);
        if (!$result['success']) {
            return $result;
        }
        $dataDebitur = $result['dataDebitur'];

        return DB::transaction(function () use($request, &$user, &$dataDebitur, $mitraAlias, $tenantId) {
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
            $kurHdrPayload = KURGeneratePayload::generateHeaderKUR($request, $user, $trxNo, $mitraAlias);
            $this->repository->insertHeaderKur($kurHdrPayload);
            $kurTrxPayload = KURGeneratePayload::generateTrxKUR($request->data, $trxNo);
            $kur = $this->repository->insertTrxKur($kurTrxPayload);
            $kurId = $kur->getKey();
            $lastLoan = $this->repository->getLastLoanNumber($mitraAlias, $currentYear);
            $startSeq = 1;
            if ($lastLoan) {
                $lastSeq = (int) substr($lastLoan, -4);
                $startSeq = $lastSeq + 1;
            }

            $institutionMap = [];
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');
            $rowInstitution = collect(data_get($request->data, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey, $mitraAlias, $tenantId) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();
                    $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                    if($nik) {
                        $institutionMap[$nik] = $instId;
                    }
                    return [
                        'category' => 'P',
                        'mitra_id' => $mitraAlias,
                        'tenant_id' => $tenantId,
                        'id_issued_location' => '-',
                        'id_issued_location' => '-',
                        'id_add_issued_location' => '-',
                        'id_add_type' => "-",
                        'created_by' => $user->user_id,
                        'full_name' => $value['full_name'] ?? null,
                        'home_province' => $value['home_province'] ?? null,
                        'home_city' => $value['home_city'] ?? 0,
                        'home_district' => $value['home_district'] ?? null,
                        'home_sub_district' => $value['home_sub_district'] ?? null,
                        'home_zipcode' => $value['home_zipcode'] ?? null,
                        'birth_place' => $value['birth_place'] ?? null,
                        'birth_date' => $enc($value['birth_date'] ?? null),
                        'gender' => $value['gender'] ?? null,
                        'id_type' => $value['id_type'] ?? null,
                        'id_number' => $enc($nik),
                        'id_number_hash' => $nikHashed,
                        'job_id' => $value['job_id'] ?? null,
                        'job_level' => $value['job_level'] ?? null,
                        'job_employer_name' => $value['job_employer_name'] ?? null,
                        'job_start_date' => $value['job_start_date'] ?? null,
                        'job_industry_type' => $value['job_industry_type'] ?? null,
                        'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
                        'phone_1'    => $enc($value['phone_1'] ?? null),
                        'email_1'    => $enc($value['email_1'] ?? null),
                        'tax_id' => $enc($value['npwp']),
                        'current_salary_currency' => $value['current_salary_currency'],
                        'tax_type' => 'npwp',
                        'institution_id' => $instId,
                        'created_at' => $nowJakarta
                    ];
                })
                ->values()
                ->all();
            if(!empty($rowInstitution)) {
                Institution::insert($rowInstitution);
            }
            $countDebitur = count($dataDebitur);
            // dd($institutionMap);
            $prefix = $mitraAlias . $currentYear;
            $rows = collect($dataDebitur)
                ->pluck('debitur_kur')
                ->filter()
                ->map(function (array $d, int $idx) use ($request, $kurId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $spSequence = $idx + 1;
                    $baseSp = $request->data['noSuratPermohonan'];
                    $realisasi = Carbon::parse($d['tanggal_realisasi']);
                    $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                    $jwBulan   = (int) ($d['jw_bulan'] ?? 0);
                    $tglAkhir = $realisasi->copy()->addMonthsNoOverflow($jwBulan);
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                    // $nik = $d['nik'] ?? null;
                    $nik = $d['nomor_identitas_1'] ?? null;
                    return [
                        'kur_trx_id' => $kurId,
                        'nama_nasabah' => $d['debitur_name'] ?? null,
                        'alamat_nasabah' => $d['debitur_address'] ?? null,
                        'penggunaan_kredit' => $d['penggunaan_kredit'] ?? null,
                        'plafond_kredit' => $d['plafond_kredit'] ?? 0,
                        'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
                        'tanggal_usia' => $d['tgl_lahir'],
                        'instansi' => $d['instansi'] ?? null,
                        'suku_bunga' => $d['suku_bunga'] ?? null,
                        'jangka_waktu' => $d['jangka_waktu'] ?? null,
                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                        'jenis_agunan' => $d['jenis_agunan'] ?? null,
                        'nilai_agunan' => $d['nilai_agunan'] ?? null,
                        'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                        'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
                        'ijp' => $d['ijp'] ?? null,
                        'loan_number' => $loanNumber,
                        'base_plafond' => $d['base_plafond'] ?? null,
                        'jenis_kredit' => $d['jenis_kredit'] ?? null,
                        'sp3' => $d['sp3'] ?? null,
                        'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
                        'status_debitur' => $d['status_debitur'] ?? null,
                        'limit_penarikan' => $d['limit_penarikan'] ?? null,
                        'npwp_principal' => $d['npwp_giro'] ??null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        // 'no_sp_detail' => $d['nilai_agunan'] ?? null,
                        // 'no_sp_core_debitur' => $d['nilai_agunan'] ?? null,
                        'institution_id' => $nik ? ($institutionMap[$nik] ?? null) : null,
                        'created_at' => $nowJakarta
                    ];
                })
                ->values()
                ->all();
            // dd($rows);
            if(!empty($rows)) {
                TrxDebiturDefaultBase::insert($rows);
            }

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);
            $savedAttachments = [];
            $kurAttachmentFolder = 'uploads/penjaminan/kur';
            foreach($debiturFiles as $idx => $attachments) {
                $nik = data_get($debiturInputs, "{$idx}.debitur_kur.nomor_identitas_1")
                    ?? data_get($debiturInputs, "{$idx}.attachments.nomor_identitas_1")
                    ?? 'UNKNOWN_NIK';
                
                foreach($attachments as $fileKey => $fileOrArray) {
                    if(is_array($fileOrArray)) {
                        foreach($fileOrArray as $innerKey => $file) {
                            if($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $unique = uniqid();
                                $fn = "{$nik}-{$innerKey}-kur-{$unique}";
                                $path = $file->storeAs(
                                    $kurAttachmentFolder,
                                    $fn . "." . $ext,
                                    's3'
                                );

                                $savedAttachments[] = [
                                    'trx_no' => $trxNo,
                                    'lampiran_id' => $innerKey,
                                    'file_name' => $fn,
                                    // 'file_info' => $file->getClientOriginalName(),
                                    'status_doc' => 'N',
                                    'version' => 1,
                                    'mime_type' => $file->getMimeType(),
                                    'file_info' => $path,
                                    'created_at' => $nowJakarta
                                ];
                            }
                        }
                    } else {
                        $file = $fileOrArray;
                        if($file instanceof \Illuminate\Http\UploadedFile) {
                            $ext = $file->getClientOriginalExtension();
                            $unique = uniqid();
                            $fn = "{$trxNo}-ktp-kur-{$idx}-{$fileKey}";
                            $path = $file->storeAs(
                                $kurAttachmentFolder,
                                $fn . "." . $ext,
                                's3'
                            );

                            $savedAttachments[] = [
                                'trx_no' => $trxNo,
                                'lampiran_id' => $fileKey,
                                'file_name' => $fn,
                                // 'file_info' => $file->getClientOriginalName(),
                                'status_doc' => 'N',
                                'version' => 1,
                                'mime_type' => $file->getMimeType(),
                                'file_info' => $path,
                                'created_at' => $nowJakarta
                            ];
                        }
                    }
                }
            }
            $this->storeAttachments($savedAttachments);

            if ($request->data['trx_status'] != 'D') {
                $this->repository->insertPenjaminanKurFlow(
                    $trxNo,
                    $request->data['trx_status'],
                    $user
                );
            }

            return [
                'success' => true
            ];
        });
    }

    public function kurDraftUpdate(Request $request, $user, $trx_no)
    {
        $mitraData = $this->getTenantMitraDataOrFail($user->mitra_id);
        $mitraAlias = $mitraData->alias;
        $tenant_ID = $mitraData->tenant_id;
        $this->validatePayloadByStatus($request);
        $penjaminanPKSResponse = $this->getPenjaminanPKS($mitraAlias);
        $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
        if(empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
            throw new Exception($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
        }
        $selectedPks = $request->data['selectedPks'];
        $dataDebitur = $request->input('data.dataDebitur', []);
        $result = KURValidate::validateDebiturBatch([
            'selectedPks' => $selectedPks,
            'penjaminanPKSData' => $penjaminanPKSData,
            'dataDebitur' => $dataDebitur
        ]);
        if (!$result['success']) {
            return $result;
        }
        $dataDebitur = $result['dataDebitur'];

        return DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias, $tenant_ID, $trx_no) {
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');

            // update header data
            $updateHeaderPayload = KURGeneratePayload::generateUpdateDraftHeader($request, $user);
            $this->repository->updateHeaderKur($trx_no, $updateHeaderPayload);
            // update trx product data
            $updateTrxPayload = KURGeneratePayload::generateUpdateDraftTrx($request->data);
            $kur = $this->repository->updateTrxKur($trx_no, $updateTrxPayload);
            $kurId = $kur->getKey();

            if (!empty($request->data['dataInstitution'])) {
                $institutionMap = [];
                $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                    ->pluck('institution_data')
                    ->filter()
                    ->map(function ($value, $idx) use ($mitraAlias, $nowJakarta, &$institutionMap, &$user, $key, $hashKey, $tenant_ID) {
                        $nik = $value['id_number'] ?? null;
                        $instId = (string) Str::uuid();
                        $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                        $institutionMap[$nik] = $instId;
                        $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

                        return [
                            'category' => 'P',
                            'mitra_id' => $mitraAlias,
                            'tenant_id' => $tenant_ID,
                            'id_issued_location' => '-',
                            'id_add_issued_location' => '-',
                            'id_add_type' => "-",
                            'created_by' => $user->user_id,
                            'full_name' => $value['full_name'] ?? null,
                            'home_province' => $value['home_province'] ?? null,
                            'home_city' => $value['home_city'] ?? 0,
                            'home_district' => $value['home_district'] ?? null,
                            'home_sub_district' => $value['home_sub_district'] ?? null,
                            'home_zipcode' => $value['home_zipcode'] ?? null,
                            'birth_place' => $value['birth_place'] ?? null,
                            'birth_date' => $enc($value['birth_date'] ?? null),
                            'gender' => $value['gender'] ?? null,
                            'id_type' => $value['id_type'] ?? null,
                            'id_number' => $enc($nik),
                            'id_number_hash' => $nikHashed,
                            'job_id' => $value['job_id'] ?? null,
                            'job_level' => $value['job_level'] ?? null,
                            'job_employer_name' => $value['job_employer_name'] ?? null,
                            'job_start_date' => $value['job_start_date'] ?? null,
                            'job_industry_type' => $value['job_industry_type'] ?? null,
                            'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
                            'phone_1' => $enc($value['phone_1'] ?? null),
                            'email_1' => $enc($value['email_1'] ?? null),
                            'tax_id' => $enc($value['npwp']),
                            'current_salary_currency' => $value['current_salary_currency'],
                            'tax_type' => 'npwp',
                            'institution_id' => $instId,
                            'created_at' => $nowJakarta,
                        ];
                    })
                    ->values()
                    ->all();

                if (!empty($rowInstitutions)) {
                    Institution::insert($rowInstitutions);
                }
            } else {
                $institutionMap = [];
            }

            if (!empty($dataDebitur)) {
                // Get loan number sequence
                $currentYear = date('Y');
                $mitraId = $mitraAlias;
                $prefix = $mitraId . $currentYear;
                $lastLoan = TrxDebiturDefaultBase::lockForUpdate()
                    ->where('loan_number', 'like', $prefix . '%')
                    ->orderBy('loan_number', 'desc')
                    ->value('loan_number');
                $startSeq = 1;
                if ($lastLoan) {
                    $lastSeq = (int) substr($lastLoan, -4);
                    $startSeq = $lastSeq + 1;
                }

                $countDebitur = count($dataDebitur);
                $rows = collect($dataDebitur)
                    ->pluck('debitur_kur')
                    ->filter()
                    ->map(function (array $d, int $idx) use ($request, $kurId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                        $spSequence = $idx + 1;
                        $baseSp = $request->data['noSuratPermohonan'];
                        $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                        $seq = $startSeq + $idx;
                        $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                        $nik = $d['nomor_identitas_1'] ?? null;

                        return [
                            'kur_trx_id' => $kurId,
                            'nama_nasabah' => $d['debitur_name'] ?? null,
                            'alamat_nasabah' => $d['debitur_address'] ?? null,
                            'penggunaan_kredit' => $d['penggunaan_kredit'] ?? 0,
                            'plafond_kredit' => $d['plafond_kredit'] ?? null,
                            'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
                            'tanggal_usia' => $d['tgl_lahir'] ?? null,
                            'instansi' => $d['instansi'] ?? null,
                            'suku_bunga' => $d['suku_bunga'] ?? null,
                            'jangka_waktu' => $d['jangka_waktu'] ?? null,
                            'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                            'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                            'jenis_agunan' => $d['jenis_agunan'] ?? null,
                            'nilai_agunan' => $d['nilai_agunan'] ?? null,
                            'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                            'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
                            'ijp' => $d['ijp'] ?? null,
                            'loan_number' => $loanNumber,
                            'base_plafond' => $d['base_plafond'] ?? null,
                            'jenis_kredit' => $d['jenis_kredit'] ?? null,
                            'sp3' => $d['sp3'] ?? null,
                            'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
                            'status_debitur' => $d['status_debitur'] ?? null,
                            'limit_penarikan' => $d['limit_penarikan'] ?? null,
                            'npwp_principal' => $d['npwp_giro'] ?? null,
                            'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                            // 'institution_id' => $institutionMap[$idx] ?? null,
                            'institution_id' => $nik ? ($institutionMap[$nik] ?? null) : null,
                            'created_at' => $nowJakarta,
                        ];
                    })
                    ->values()
                    ->all();

                if (!empty($rows)) {
                    TrxDebiturDefaultBase::insert($rows);
                }
            }

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);

            if (!empty($debiturFiles)) {
                DB::table('penjaminan_lampiran_dtl')->where('trx_no', $trx_no)->delete();

                $savedAttachments = [];
                $kurAttachmentFolder = 'uploads/penjaminan/kur';
                foreach ($debiturFiles as $idx => $attachments) {
                    // $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik");
                    $nik = data_get($debiturInputs, "{$idx}.debitur_kur.nomor_identitas_1")
                        ?? data_get($debiturInputs, "{$idx}.attachments.nomor_identitas_1")
                        ?? 'UNKNOWN_NIK';

                    foreach ($attachments as $fileKey => $fileOrArray) {
                        if (is_array($fileOrArray)) {
                            foreach ($fileOrArray as $innerKey => $file) {
                                if ($file instanceof \Illuminate\Http\UploadedFile) {
                                    $ext = $file->getClientOriginalExtension();
                                    $unique = uniqid();
                                    $fn = "{$nik}-{$innerKey}-kur-{$unique}";
                                    $path = $file->storeAs(
                                        $kurAttachmentFolder,
                                        $fn . "." . $ext,
                                        's3'
                                    );

                                    $savedAttachments[] = [
                                        'trx_no' => $trx_no,
                                        'lampiran_id' => $innerKey,
                                        'file_name' => $fn,
                                        'status_doc' => 'N',
                                        'version' => 1,
                                        'mime_type' => $file->getMimeType(),
                                        'file_info' => $path,
                                        'created_at' => $nowJakarta
                                    ];
                                }
                            }
                        } else {
                            $file = $fileOrArray;
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $fn = "{$trx_no}-ktp-kur-{$idx}-{$fileKey}";
                                $path = $file->storeAs(
                                    $kurAttachmentFolder,
                                    $fn . "." . $ext,
                                    's3'
                                );

                                $savedAttachments[] = [
                                    'trx_no' => $trx_no,
                                    'lampiran_id' => $fileKey,
                                    'file_name' => $fn,
                                    'status_doc' => 'N',
                                    'version' => 1,
                                    'mime_type' => $file->getMimeType(),
                                    'file_info' => $path,
                                    'created_at' => $nowJakarta
                                ];
                            }
                        }
                    }
                }
                $this->storeAttachments($savedAttachments);
            }

            if ($request->data['trx_status'] != 'D') {
                $this->repository->deleteKurFlow($trx_no);
                $this->repository->insertPenjaminanKurFlow(
                    $trx_no,
                    $request->data['trx_status'],
                    $user
                );
            }
            return [
                'success' => true
            ];
        });
    }

    public function kurApproval(string $trx_no, $user)
    {
        ini_set('max_execution_time', 0);
        DB::transaction(function () use ($trx_no, $user) {
            Log::info('User ID for approval: ' . $user->user_id . ' Name: ' . $user->name);
            $penjaminan = $this->checkPenjaminanIsNotSynced($trx_no);
            Log::info('regist Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'KURService@kurApproval',
                'time' => now()->toDateTimeString(),
            ]);

            $debiturs = $this->repository->getDebiturKur($penjaminan->id_kur);
            $allCAC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CAC');
            $allCBC = $debiturs->every(fn($d) => ($d->jenis_penjaminan ?? null) === 'CBC');
            $finalTrxStatus = null;
            if ((!$allCAC && !$allCBC) || ($allCAC)) {
                $finalTrxStatus = 'WFP';
            } elseif ($allCBC) {
                $finalTrxStatus = 'S';
            }
            $institutionIds = $debiturs->pluck('institution_id')->filter()->unique()->values();
            $institutionsById = $this->repository->getInstitutionByListId($institutionIds);
            $debitursArray = $debiturs->map(function ($d) use ($institutionsById) {
                $arr = $d->toArray();
                $arr['nik'] = $d->nik;
                $arr['birth_date'] = $institutionsById[$d->institution_id]->birth_date ?? null;
                $arr['gender'] = $institutionsById[$d->institution_id]->gender ?? null;
                return $arr;
            })->values()->all();
            $nowJakarta = Carbon::now('Asia/Jakarta');
            Log::info('END regist Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'KURService@kurApproval',
                'time' => now()->toDateTimeString(),
            ]);
            $binaryLampiran = $this->repository->getLampiranKur($trx_no);
            $lampiranCodeList = array_unique($binaryLampiran->pluck('lampiran_id')->toArray());
            $lampiranJenisMapping = $this->repository->getLampiranMappingKur($lampiranCodeList);

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            Log::info('iterate lampiran Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'KURService@kurApproval',
                'time' => now()->toDateTimeString(),
            ]);
            foreach ($binaryLampiran as $bin) {
                Log::info('Get NIK and base64 lampiran ' .  $bin->lampiran_id, [
                    'endpoint' => 'KURService@kurApproval',
                    'time' => now()->toDateTimeString(),
                ]);
                $binFileNameParse = explode('/', $bin->file_info);
                $debiturFileName = $binFileNameParse[count($binFileNameParse) - 1];
                $fileNameParse = explode('-', $debiturFileName);
                $fileNik = $fileNameParse[0];
                $binS3Content = Storage::disk('s3')->get($bin->file_info);
                $binS3Base64 = base64_encode($binS3Content);
                $jenisByLampiranId = $lampiranJenisMapping
                    ->firstWhere('value', strtolower($bin->lampiran_id));
                $namaJenis = $jenisByLampiranId ? $jenisByLampiranId->option3 : "";
                LOG::info("check jenis dokumen {$jenisByLampiranId}");
                // check if lampiran id is in list of lampiran perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Perorangan to Creatio', [
                        'endpoint' => 'KURService@kurApproval',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Perorangan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Perorangan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat umum
                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran '  .  $namaJenis . ' Syarat Umum to Creatio', [
                        'endpoint' => 'KURService@kurApproval',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Umum " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }

                // check if lampiran id is in list of lampiran syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $payloadDocument = [
                        "NIK" => $fileNik,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" => $debiturFileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $binS3Base64
                    ];
                    Log::info('Sending lampiran ' .  $namaJenis . ' Syarat Khusus to Creatio', [
                        'endpoint' => 'KURService@kurApproval',
                        'time' => now()->toDateTimeString(),
                    ]);
                    // $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    // LOG::info("check inside looping {$response} {$namaJenis} {$debiturFileName}");
                    // if ($response->status() !== 200) {
                    //     throw new Exception("Failed to Send Document penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    // }
                    // $bodyResponse = json_decode($response->body(), true);
                    // if ($bodyResponse['Success'] !== true) {
                    //     throw new Exception("Failed to Send penjaminan Syarat Khusus " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    // }
                }
            }
            Log::info('END iterate lampiran Kredit Usaha Rakyat (KUR)', [
                'endpoint' => 'KURService@kurApproval',
                'time' => now()->toDateTimeString(),
            ]);
            // temporarily remark send notif to core
            // $notifCreatioPayload = [
            //     "Title" => "Mitra Portal Notification",
            //     "Subject" => "Register Penjaminan Success",
            //     // "Contact" => $request->nama
            //     "Contact" => "Supervisor"
            // ];
            // $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);
            // $notifResBody = json_decode($notifRes->body(), true);
            // (END) temporarily remark send notif to core

            // $noTelp = $penjaminan->no_telp;
            // if (str_starts_with($noTelp, '0')) {
            //     $noTelp = '62' . substr($noTelp, 1); 
            // }
            // LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");
            $this->repository->updateApprovalHeaderKur($trx_no, $finalTrxStatus, 1, $user);
            $this->repository->insertPenjaminanKurFlow(
                $trx_no,
                $finalTrxStatus,
                $user,
                true
            );
            $this->repository->insertNotifApprovalKur($trx_no, $user);
            Log::info("Penjaminan KUR {$trx_no} approved successfully.");
        });
        
    }

    public function pembayaranManualKur(Request $request, $user)
    {
        $itemValidation = KURValidate::validateItemPambayaranManual($request->selected_items);
        if(!$itemValidation['success']) {
            return $itemValidation;
        }
        $invoiceNumbers = $this->getPayloadInvoiceNumbers($request->selected_items);
        return DB::transaction(function () use ($request, $user, $invoiceNumbers) {
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $trxNo = $request->trx_no;
            $kurHeader = $this->getSuratPermohonanKurOrFail($trxNo);
            $tenorData = $this->getTenorDebiturOrFail($trxNo, $invoiceNumbers);
            $amountSum = $tenorData->sum('amount');
            if ($amountSum != $request->amount) {
                return [
                    'success' => false,
                    'message' => 'Incorrect amount.'
                ];
            }
            $noSuratPermohonan = $kurHeader->no_surat_permohonan;
            $invoiceHeaderPayload = KURGeneratePayload::generateInvoiceHeader($trxNo, $tenorData, true);
            $invoiceHeaderData = $this->repository->insertDebiturInvoiceHeader($invoiceHeaderPayload);
            $newInvoiceId = $invoiceHeaderData->invoice_id;
            $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
            $this->repository->insertPaymentGatewayManual($newInvoiceId, $orderId, $amountSum);
            $scheduleIdList = $tenorData->pluck('schedule_id');
            $this->repository->updateDebiturStatus($scheduleIdList, 'Paid', $newInvoiceId);
            $attachmentBuktiBayar = $request->file('file');
            $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));
            $ext = $attachmentBuktiBayar->getClientOriginalExtension();
            $fn = $orderId . '-pembayaran-kur';

            $debiturPayload = $tenorData->map(function ($itemDebitur) {
                return [
                    'no_sp_detail' => $itemDebitur->no_sp_detail,
                    'invoice_number' => $itemDebitur->invoice_number,
                    'total_amount' => $itemDebitur->amount
                ];
            })->toArray();
            $creatioPayload = [
                'NoSuratPermohonan' => $noSuratPermohonan,
                'ListDebitur' => $debiturPayload,
                'NamaFile' => $fn . '.' . $ext,
                'DataBase64' => $fileBase64
            ];
            // pending core API ready
            // $svcCreatio = new CreatioService();
            // $response = $svcCreatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', $creatioPayload);
            // if ($response->status() !== 200) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
            // }
            // $bodyResponse = json_decode($response->body(), true);
            // if ($bodyResponse['Success'] !== true) {
            //     throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
            // }
            // (END) pending core API ready
            $localStackPath = $attachmentBuktiBayar->storeAs(
                'uploads/penjaminan/payment-multiguna',
                $fn . '.' . $ext,
                's3'
            );
            $savedAttachments = [
                [
                    'trx_no' => $trxNo,
                    'lampiran_id' => 'pembayaran',
                    'file_name' => $fn,
                    'status_doc' => 'N',
                    'version' => 1,
                    'mime_type' => $attachmentBuktiBayar->getMimeType(),
                    'file_info' => $localStackPath,
                    'created_at' => $nowJakarta
                ]
            ];
            $this->storeAttachments($savedAttachments);
            // PenjaminanTransaction::where('trx_no', $trxNo)
            //     ->update([
            //         'trx_status' => 'PD',
            //         'updated_at' => $nowJakarta 
            //     ]);
            // PenjaminanFlow::insert([
            //     'trx_no' => $trxNo,
            //     'trx_status' => 'PD',
            //     'created_at' => $nowJakarta,
            //     'updated_at' => $nowJakarta,
            //     'created_by_id' => $userData->user_id,
            //     'created_by_name' => $userData->name
            // ]);
            return [
                'success' => true
            ];
        });
    }

    public function getDataHeaderPaymentFull(Request $request)
    {
        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $key = base64_decode(config('services.secure.key'));
        $dataHeader = $this->getFullPaymentPendingOrFail($trx_no, $no_surat_permohonan, $isSplit);
        $dataHeader->each(function ($row) use ($key) {
            $decryptedIdNumber = AesHelper::decrypt($row->id_number, $key);

            $row->id_number = $decryptedIdNumber;
        });
        return $dataHeader;
    }

    public function getDataUnpaidPaymentFull($trx_no)
    {
        $dataUnpaid = $this->repository->getPaymentUnpaidFull($trx_no);
        return $dataUnpaid;
    }

    public function getSplitPaymentDetail(Request $request)
    {
        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $dataHeader = $this->getSplitPaymentHeaderOrFail($trx_no, $no_surat_permohonan, $isSplit);
        $dataDebitur = $this->repository->getDebiturSplitPaymentKur($dataHeader->id_kur);
        $debiturById = $dataDebitur->keyBy('id_trx_debitur');
        $debiturIds  = $dataDebitur->pluck('id_trx_debitur')->filter()->unique()->values();
        if ($debiturIds->isEmpty()) {
            return [];
            // return response()->json(['data' => []]);
        }
        $schedules = $this->repository->getTenorScheduleByDebiturId($debiturIds, ['Unpaid', 'Pending']);
        $schedulesUnpaid = $this->repository->getPaymentUnpaidSplit($debiturIds);
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
        return $result;
    }

    private function getTenantMitraDataOrFail($mitra_id) {
        $tenantData = $this->repository->getTenantMitraData($mitra_id);
        if(!$tenantData) {
            throw new NotFoundException('Tenant mitra data is not found.');
        }
        return $tenantData;
    }

    private function getSuratPermohonanKurOrFail($trx_no) {
        $data = $this->repository->getSuratPemohonanKur($trx_no);
        if(!$data) {
            throw new NotFoundException('Penjaminan KUR not found.');
        }
        return $data;
    }

    private function getTenorDebiturOrFail($trx_no, array $invoice_numbers)
    {
        $tenor = $this->repository->getTenorDebitur($trx_no, $invoice_numbers);
        if(count($tenor) < 1) {
            throw new NotFoundException('Tenor debitur KUR is not found.');
        }
        return $tenor;
    }

    private function getFullPaymentPendingOrFail($trx_no, $no_sp, $is_split)
    {
        $data = $this->repository->getPaymentPendingFull($trx_no, $no_sp, $is_split);
        if(!$data) {
            throw new NotFoundException('Data tidak ditemukan.');
        }
        return $data;
    }

    private function getSplitPaymentHeaderOrFail($trx_no, $no_sp, $is_split)
    {
        $data = $this->repository->getPaymentHeaderSplit($trx_no, $no_sp, $is_split);
        if(!$data)
        {
            throw new NotFoundException('Data tidak ditemukan.');
        }
        return $data;
    }

    private function checkPenjaminanIsNotSynced($trx_no)
    {
        $data = $this->repository->getHeaderKurJoinTrx($trx_no);
        if(!$data)
        {
            throw new NotFoundException('Penjaminan '. $trx_no . ' is not found or already synced.');
        }
        return $data;
    }

    public function showKURDetail($trx_no) {
        try {
            $penjaminanDetail = $this->repository->getPenjaminanDetail($trx_no);
            if(!$penjaminanDetail)
            {
                throw new Exception('Data not found.', 404);
            }
            $rows = $this->repository->getDebiturWithInstitution($penjaminanDetail->id_kur);
            $lampiran = $this->repository->getLampiranKURDetail($trx_no);
            if($rows->isNotEmpty()) {
                $key = base64_decode(config('services.secure.key'));
                foreach($rows as $row) {
                    if ($row->birth_date) {
                        $row->birth_date = AesHelper::decrypt($row->birth_date, $key);
                    }
                    if ($row->id_number) {
                        $row->id_number = AesHelper::decrypt($row->id_number, $key);
                    }
                    if ($row->tax_id) {
                        $row->tax_id = AesHelper::decrypt($row->tax_id, $key);
                    }
                    if ($row->email_1) {
                        $row->email_1 = AesHelper::decrypt($row->email_1, $key);
                    }
                    if ($row->phone_1) {
                        $row->phone_1 = AesHelper::decrypt($row->phone_1, $key);
                    }
                    $row->attachments = [];
                }

                foreach($lampiran as $att) {
                    $filename = $att->file_name ?? basename($att->file_path ?? '');
                    if (!$filename) {
                        continue;
                    }
                    $parts = explode('-', $filename);
                    $fileNik = $parts[0] ?? null;
                    if (!$fileNik) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!empty($row->id_number) && $row->id_number === $fileNik) {
                            $item = [
                                'id' => $att->id ?? null,
                                'file_path' => $att->file_info ?? null,
                                'key_lampiran' => $att->lampiran_id ?? null,
                                'is_additional' => $att->is_additional ?? null,
                                'status_doc' => $att->status_doc ?? null,
                                'uploaded_at' => $att->created_at ?? null,
                                'blob' => [
                                    'name' => $att->file_name ?? null,
                                ],
                                // 'presigned_url' => Storage::disk('s3')->temporaryUrl(
                                //     $att->file_info,
                                //     now()->addMinutes(15)
                                // ),
                            ];
                            $row->attachments[] = $item;
                        }
                    }
                }
            }
            $kurFlow = $this->repository->getKURFlow($trx_no);
            if ($kurFlow != null) {
                $penjaminanDetail->flowMultiguna = $kurFlow;
            }
            if ($rows != null) {
                $penjaminanDetail->debiturKur = $rows;
            }
            return $penjaminanDetail;
        } catch(Exception $ex) {
            throw new Exception($ex->getMessage(), 500);
        }
    }

    private function validatePayloadByStatus(Request $request)
    {
        switch($request->data['trx_status']) {
            case 'D':
                if($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
                    throw ValidationException::withMessages([
                        'data.dataDebitur' => [
                            'Excel tidak boleh diisi Jika dalam Ingin Save as Draft'
                        ]
                    ]);
                }
                break;
            default:
                if(!$request->has('data.dataDebitur') || empty($request->input('data.dataDebitur', []))) {
                    throw ValidationException::withMessages([
                        'data.dataDebitur' => [
                            'Data debitur tidak boleh kosong jika Draft ingin di-submit'
                        ]
                    ]);
                }
                else if(empty($request->allFiles())) {
                    throw ValidationException::withMessages([
                        'data.dataDebitur.attachments' => [
                            'File upload wajib diisi (tidak ada file yang dikirim)'
                        ]
                    ]);
                }
                break;
        }
    }

    private function getPenjaminanPKS($mitraAlias)
    {
        $pksService = new CreatioService();
        $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
            'MitraID' => $mitraAlias
        ]);
        // dd($response);
        if($response->status() !== 200) {
            throw new Exception("Failed to get data from Core Creatio API with status: " . $response->status());
        }
        $apiResBody = json_decode($response->body(), true);
        if ($apiResBody['Success'] !== true) {
            throw new Exception("Failed to get data from Core Creatio API with message: " . $apiResBody['Message']);
        }
        return response()->json($apiResBody);
    }

    private function getPayloadInvoiceNumbers($selected_items)
    {
        $parsed = json_decode($selected_items);
        return collect($parsed)->pluck('invoice_number')->toArray();
    }

    private function storeAttachments(array $attachments)
    {
        if(!empty($attachments))
        {
            $this->repository->insertAttachmentsKur($attachments);
        }
    }
}
