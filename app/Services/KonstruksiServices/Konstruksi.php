<?php

namespace App\Services\KonstruksiServices;

use App\Helpers\ValidateDebitur;
use App\Helpers\AesHelper;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Repositories\KonstruksiRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Konstruksi
{
    public function __construct(protected KonstruksiRepository $repository) {}

    public function getTenantMitra(string $mitraId)
    {
        return $this->repository->getTenantMitra($mitraId);
    }

    public function getDetail(string $trx_no)
    {
        //
        $key = base64_decode(config('services.secure.key'));

        $penjaminanDetail = $this->repository->getPnjTrx($trx_no);
        if (!$penjaminanDetail) {
            return null;
        }

        $rows = $this->repository->getInst($penjaminanDetail->id_multiguna_konstruksi);

        $lampiran = $this->repository->getLampiranDtl($trx_no);

        if ($rows->isNotEmpty()) {
            $key = base64_decode(config('services.secure.key'));
            $rows = $this->decryptInstitution($rows, $key);
            $lampiran = $this->getLampiran($trx_no);
            $penjaminanDetail->setAttribute('lampiran', $lampiran);
        }
        // flow multiguna
        $MultigunaFlow = $this->repository->getFlow($trx_no);
        if ($MultigunaFlow != null) {
            $penjaminanDetail->flowMultiguna = $MultigunaFlow;
        }

        if ($rows != null) {
            $penjaminanDetail->debiturMultiguna = $rows;
        }

        return $penjaminanDetail;
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
        foreach ($data as $rows) {
            foreach ($fields as $field) {
                $rows->$field = !empty($rows->$field)
                    ? AesHelper::decrypt($rows->$field, $key)
                    : null;
            }
        }

        return $data;
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

        $lampiranData = $this->repository->lampiranData($lampiranLatest);

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

    public function store($request, $user, string $mitraAlias, string $tenant_ID, array $penjaminanPKSData = null)
    {
        //
        if (empty($request->allFiles()) && $request->data['trx_status'] != 'D') {
            return [
                'success' => false,
                'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
            ];
        }

        $selectedPks = $request->data['selectedPks'];
        $dataDebitur = $request->input('data.dataDebitur', []);

        $result = ValidateDebitur::validateDebiturBatch(['selectedPks' => $selectedPks, 'penjaminanPKSData' => $penjaminanPKSData, 'dataDebitur' => $dataDebitur]);
        $dataDebitur = $result['dataDebitur'];
        if (!$result['success']) {
            return $result;
        }

        DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias, $tenant_ID) {
            //
            $currentYear = date('Y');
            $currentMonth = date('m');
            $lastTrx = $this->repository->getLatestTrxNoByPeriod($currentYear, $currentMonth);

            if ($lastTrx) {
                $lastSequence = intval(substr($lastTrx, -4));
                $nextSeq = $lastSequence + 1;
            } else {
                $nextSeq = 1;
            }

            $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
            $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
            $nowJakarta = $this->repository->getNowJakarta();
            $spSplit = $request->boolean('data.spSplit');

            $this->repository->createPenjaminanTransaction([
                'trx_no' => $trxNo,
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->data['trx_status'],
                'status_sync_creatio' => 0,
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'product' => 'kkpbj',
                'mitra_id' => $mitraAlias,
                'no_rek' => '012312'
            ]);

            $konstruksi = $this->repository->createKonstruksiTransaction([
                'trx_no' => $trxNo,
                // 'jenis_product' => $request->['jenisBond'],
                'jenis_product_description' => 'konstruksi',
                'pks_number' => $request->data['pks'],
                'fee_base_number' => $request->data['feeBasePercentage'],
                'fee_base_percentage' => $request->data['feeBasePercentage'],
                'bank_name' => $request->data['bankCabang'],
                'bank_code' => $request->data['bank'],
                'text_certified' => $request->data['teksPenjaminanSp'],
                'created_at' => $nowJakarta,
            ]);

            $multigunaId = $konstruksi->getKey();
            $mitraId = $mitraAlias; //$request->data['mitra_id'];
            $prefix = $mitraId . $currentYear;
            $lastLoan = $this->repository->getLatestLoanNumber($prefix);

            $startSeq = 1;
            if ($lastLoan) {
                $lastSeq = (int) substr($lastLoan, -4);
                $startSeq = $lastSeq + 1;
            }

            $institutionMap = [];
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');
            // dd($request->data['dataInstitution']);

            $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey, $mitraId, $tenant_ID) {
                    //
                    // $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $enc = function ($value) use ($key) {
                        return filled($value)
                            ? AesHelper::encrypt($value, $key)
                            : null;
                    };

                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();
                    $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                    if ($nik) {
                        $institutionMap[$nik] = $instId;
                    }
                    return [
                        'category' => 'P',
                        'mitra_id' => $mitraId,
                        'tenant_id' => $tenant_ID, //'2185e11e-35a6-4c89-aa3f-4645451e0536',
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
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertInstitutions($rowInstitutions);

            $countDebitur = count($dataDebitur);
            $rows = collect($dataDebitur)->pluck('debitur_multiguna')->filter()
                ->map(function (array $d, int $idx) use ($request, $multigunaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                    //$enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $enc = function ($value) use ($key) {
                        return filled($value)
                            ? AesHelper::encrypt($value, $key)
                            : null;
                    };
                    $spSequence = $idx + 1;
                    $baseSp = $request->data['noSuratPermohonan'];
                    $realisasi = Carbon::parse($d['tanggal_realisasi']);
                    $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                    $jwBulan   = (int) ($d['jw_bulan'] ?? 0);
                    $tglAkhir = $realisasi->copy()->addMonthsNoOverflow($jwBulan);
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                    $nik = $d['nik'] ?? null;

                    //change point
                    return [
                        //
                        "id_multiguna_konstruksi" => $multigunaId,
                        "nilai_penjaminan" => $d['nilai_penjaminan'] ?? null,
                        "jangka_waktu" => $d['jw_bulan'] ?? null,
                        "tanggal_realisasi" => $d['tanggal_realisasi'] ?? null,
                        "tanggal_jatuh_tempo" => $d['tanggal_jatuh_tempo'] ?? null,
                        "suku_bunga" => $d['suku_bunga'] ?? null,
                        "tanggal_kontrak" => $d['tanggal_kontrak'] ?? null,
                        "nama_proyek" => $d['nama_proyek'] ?? null,
                        "nilai_proyek" => $d['nilai_proyek'] ?? null,
                        "nilai_kredit_per_proyek" => $d['nilai_kredit_per_proyek'] ?? null,
                        "dana_diendapkan" => $d['dana_diendapkan'] ?? null,
                        "jangka_waktu_proyek" => $d['jangka_waktu_proyek'] ?? null,
                        "nomor_memo" => $d['nomor_memo'] ?? null,
                        "tanggal_memo" => $d['tanggal_memo'] ?? null,
                        "tenaga_kerja" =>  $d['tenaga_kerja'] ?? null,
                        "ijp" => $d['ijp'] ?? null,
                        "loan_number" => $loanNumber,
                        "no_sp_detail" => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        "no_sp_core_debitur" => "-",
                        "institution_id" => $nik ? ($institutionMap[$nik] ?? null) : null,
                        "created_at" => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertKonstruksiDebitur($rows);

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);
            $savedAttachments = [];
            // change point
            foreach ($debiturFiles as $idx => $attachments) {
                $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik")
                    ?? data_get($debiturInputs, "{$idx}.attachments.nik")
                    ?? 'UNKNOWN_NIK';
                foreach ($attachments as $fileKey => $fileOrArray) {
                    if (is_array($fileOrArray)) {
                        foreach ($fileOrArray as $innerKey => $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $unique = uniqid();
                                $fn = "{$nik}-{$innerKey}-kkpbj";
                                $path = $file->storeAs(
                                    'uploads/penjaminan/kkpbj',
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
                        if ($file instanceof \Illuminate\Http\UploadedFile) {
                            $ext = $file->getClientOriginalExtension();
                            $unique = uniqid();
                            $fn = "{$trxNo}-ktp-kkpbj-{$idx}-{$fileKey}";
                            $path = $file->storeAs(
                                'uploads/penjaminan/kkpbj',
                                $fn . "." . $ext,
                                's3'
                            );

                            $savedAttachments[] = [
                                'trx_no' => $trxNo,
                                // 'lampiran_id' => $innerKey,
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

            foreach ($savedAttachments as $sa) {
                $this->repository->insertLampiranDetails($sa);
            }

            if ($request->data['trx_status'] != 'D') {
                $this->repository->createPenjaminanFlow([
                    'trx_no' => $trxNo,
                    'trx_status' => $request->data['trx_status'],
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null
                ]);
            }
        });

        return ["success" => true];
    }

    public function update($request, $user, string $mitraAlias, string $tenant_ID, array $penjaminanPKSData, $trxNo)
    {
        $newStatus = $request->data['trx_status'];
        $dataDebitur = $request->input('data.dataDebitur', []);
        if ($request->data['trx_status'] !== 'D' && !empty($dataDebitur)) {
            $selectedPks = $request->data['selectedPks'] ?? $request->data['pks'];
            $result = ValidateDebitur::validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return $result;
            }
        }
        if ($newStatus === 'D') {
            if (!empty($request->allFiles())) {
                return [
                    'success' => false,
                    'message' => 'File upload tidak diperbolehkan saat Save as Draft.',
                ];
            }
        } else {
            if (empty($request->allFiles())) {
                return [
                    'success' => false,
                    'message' => 'File upload wajib diisi saat Submit.',
                ];
            }
        }

        DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias, $tenant_ID, $trxNo) {
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');

            // Get dan lock transaksi utama
            $penjaminan = $this->repository->lockPenjaminan($trxNo);

            // Update PenjaminanTransaction
            $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
            $spSplit = $request->boolean('data.spSplit');
            $this->repository->updatePenjaminanDraft($penjaminan, [
                'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->data['trx_status'],
                'status_sync_creatio' => 0,
                'sp_split' => $spSplit,
                'updated_at' => $nowJakarta,
                'updated_by_id' => $user->user_id,
                'updated_by_name' => $user->name,
            ]);


            $konstruksi = $this->repository->lockMultigunaTrxKonstruksi($trxNo);

            $konstruksi->update([
                'pks_number' => $request->data['pks'],
                'fee_base_number' => $request->data['feeBasePercentage'],
                'fee_base_percentage' => $request->data['feeBasePercentage'],
                'bank_name' => $request->data['bankCabang'],
                'bank_code' => $request->data['bank'],
                'text_certified' => $request->data['teksPenjaminanSp'],
                'updated_at' => $nowJakarta,
            ]);

            $multigunaId = $konstruksi->getKey();

            if (!empty($request->data['dataInstitution'])) {
                $institutionMap = [];
                $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                    ->pluck('institution_data')
                    ->filter()
                    ->map(function ($value, $idx) use ($mitraAlias, $nowJakarta, &$institutionMap, &$user, $key, $hashKey, $tenant_ID) {
                        $nik = $value['id_number'] ?? null;
                        $instId = (string) Str::uuid();
                        $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                        $institutionMap[$idx] = $instId;
                        $enc = function ($value) use ($key) {
                            return filled($value)
                                ? AesHelper::encrypt($value, $key)
                                : null;
                        };

                        return [
                            'category' => 'P',
                            'mitra_id' => $mitraAlias, //'MDR',
                            'tenant_id' => $tenant_ID, //'2185e11e-35a6-4c89-aa3f-4645451e0536',
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
                            'created_at' => $nowJakarta,
                        ];
                    })
                    ->values()->all();

                $this->repository->insertInstitutions($rowInstitutions);
            } else {
                $institutionMap = [];
            }

            if (!empty($dataDebitur)) {
                // Get loan number sequence
                $currentYear = date('Y');
                $mitraId = $mitraAlias;
                $prefix = $mitraId . $currentYear;
                $lastLoan = $this->repository->getLatestLoanNumber($prefix);

                $startSeq = 1;
                if ($lastLoan) {
                    $lastSeq = (int) substr($lastLoan, -4);
                    $startSeq = $lastSeq + 1;
                }

                $countDebitur = count($dataDebitur);
                $rows = collect($dataDebitur)
                    ->pluck('debitur_multiguna')
                    ->filter()
                    ->map(function (array $d, int $idx) use ($request, $multigunaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                        $spSequence = $idx + 1;
                        $baseSp = $request->data['noSuratPermohonan'];
                        $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                        $seq = $startSeq + $idx;
                        $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                        return [
                            //
                            "id_multiguna_konstruksi" => $multigunaId,
                            "nilai_penjaminan" => $d['nilai_penjaminan'] ?? null,
                            "jangka_waktu" => $d['jw_bulan'] ?? null,
                            "tanggal_realisasi" => $d['tanggal_realisasi'] ?? null,
                            "tanggal_jatuh_tempo" => $d['tanggal_jatuh_tempo'] ?? null,
                            "suku_bunga" => $d['suku_bunga'] ?? null,
                            "tanggal_kontrak" => $d['tanggal_kontrak'] ?? null,
                            "nama_proyek" => $d['nama_proyek'] ?? null,
                            "nilai_proyek" => $d['nilai_proyek'] ?? null,
                            "nilai_kredit_per_proyek" => $d['nilai_kredit_per_proyek'] ?? null,
                            "dana_diendapkan" => $d['dana_diendapkan'] ?? null,
                            "jangka_waktu_proyek" => $d['jangka_waktu_proyek'] ?? null,
                            "nomor_memo" => $d['nomor_memo'] ?? null,
                            "tanggal_memo" => $d['tanggal_memo'] ?? null,
                            "tenaga_kerja" =>  $d['tenaga_kerja'] ?? null,
                            "ijp" => $d['ijp'] ?? null,
                            "loan_number" => $loanNumber,
                            "no_sp_detail" => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                            "no_sp_core_debitur" => "-",
                            "institution_id" => $institutionMap[$idx] ?? null,
                            "created_at" => $nowJakarta,
                        ];
                    })
                    ->values()->all();
                $this->repository->insertKonstruksiDebitur($rows);
            }
            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);

            if (!empty($debiturFiles)) {
                $this->repository->deleteLampiranDetails($trxNo);

                $savedAttachments = [];
                foreach ($debiturFiles as $idx => $attachments) {
                    $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik");


                    foreach ($attachments as $fileKey => $fileOrArray) {
                        if (is_array($fileOrArray)) {
                            foreach ($fileOrArray as $innerKey => $file) {
                                if ($file instanceof \Illuminate\Http\UploadedFile) {
                                    $ext = $file->getClientOriginalExtension();
                                    $unique = uniqid();
                                    $fn = "{$nik}-{$innerKey}-kkpbj";
                                    $path = $file->storeAs(
                                        'uploads/penjaminan/kkpbj',
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
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $unique = uniqid();
                                $fn = "{$trxNo}-ktp-kkpbj-{$idx}-{$fileKey}";
                                $path = $file->storeAs(
                                    'uploads/penjaminan/kkpbj',
                                    $fn . "." . $ext,
                                    's3'
                                );

                                $savedAttachments[] = [
                                    'trx_no' => $trxNo,
                                    // 'lampiran_id' => $innerKey,
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

                $this->repository->insertLampiranDetails($savedAttachments);
            }

            if ($request->data['trx_status'] != 'D') {
                PenjaminanFlow::where('trx_no', $trxNo)->delete();

                PenjaminanFlow::create([
                    'trx_no' => $trxNo,
                    'trx_status' => $request->data['trx_status'],
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null
                ]);
            }
        });
    }
    public function GetDetailPaymentKonstruksi($request)
    {
        $key = base64_decode(config('services.secure.key'));
        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $data = [];

        $dataHeader = $this->repository->dataHeader($trx_no, $no_surat_permohonan, $isSplit);

        if (!$dataHeader) {
            return ['success' => false, 'message' => 'Data tidak ditemukan'];
        }
        $dataHeader->each(function ($row) use ($key) {
            $decryptedNik = AesHelper::decrypt($row->nik, $key);

            $row->nik = $decryptedNik;
        });

        $dataUnpaid = $this->repository->dataUnpaid($trx_no);

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'dataHeader' => [
                    'data_pending' => $dataHeader,
                    'data_unpaid' => $dataUnpaid,
                ],
            ],
        ];
    }
    public function GetDetailListPaymentKonstruksi($request)
    {
        $key = base64_decode(config('services.secure.key'));

        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $dataHeader = $this->repository->dataHeaderList($trx_no, $no_surat_permohonan, $isSplit);

        if (!$dataHeader) {
            return ['success' => false, 'message' => 'Data tidak ditemukan'];
        }

        $dataDebitur = $this->repository->dataDebitur($dataHeader->id_multiguna_konstruksi);

        $debiturById = $dataDebitur->keyBy('id_trx_debitur_konstruksi');
        $debiturIds  = $dataDebitur->pluck('id_trx_debitur_konstruksi')->filter()->unique()->values();
        if ($debiturIds->isEmpty()) {
            return ['message' => 'Debitur ID empty', 'data' => []];
        }

        $schedules = $this->repository->schedule($debiturIds);

        $schedulesUnpaid = $this->repository->scheduleUnpaid($debiturIds);

        $result = $schedules
            ->groupBy('tenor_sequence')
            ->map(function ($rows, $tenor) use ($debiturById, $schedulesUnpaid, $key) {
                $scheduleByDebitur = $rows->keyBy('id_trx_debitur');
                $unpaidSchedules = $schedulesUnpaid->where('tenor_sequence', $tenor);
                $listPending = $rows->where('status', 'Pending')->pluck('id_trx_debitur')->unique()->values()
                    ->map(function ($id) use ($debiturById, $scheduleByDebitur, $key) {

                        $d = $debiturById->get($id);
                        if (!$d) return null;
                        $sch = $scheduleByDebitur->get($id);

                        return [
                            'id_trx_debitur'    => $d->id_trx_debitur_konstruksi,
                            'no_sp_detail'      => $d->no_sp_detail,
                            'loan_number'       => $d->loan_number,
                            'nik'               => AesHelper::decrypt($d->nik, $key),
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

        return ['success' => true, 'data' => $result];
    }
    public function uploadPembayaranManual($request)
    {
        if (
            !json_validate($request->selected_items) ||
            !is_array(json_decode($request->selected_items))
        ) {
            return [
                'success' => false,
                'message' => 'Invalid selected item data.'
            ];
        }
        $parsedItem = json_decode($request->selected_items);
        // $debugFile = $request->file('file');
        // dd($debugFile->getMimeType());

        $arrInvoiceNoTemp = collect($parsedItem)->pluck('invoice_number')->toArray();
        // $arrInvoiceNoTemp = collect($request->selected_item_old)->pluck('invoice_number')->toArray();
        // dd($arrInvoiceNoTemp);
        // dd($request);
        $duplicateInvoiceNo = count($arrInvoiceNoTemp) != count(array_unique($arrInvoiceNoTemp));
        if ($duplicateInvoiceNo) {
            return [
                'success' => false,
                'message' => 'Duplicate invoice data.'
            ];
        }

        DB::beginTransaction();
        $tenorData = $this->repository->tenorData($arrInvoiceNoTemp, $request->trx_no);

        $mltHeader = $this->repository->mltHeader($request->trx_no);

        if (count($tenorData) < 1 || !$mltHeader) {
            return [
                'success' => false,
                'message' => 'Penjaminan multiguna not found.'
            ];
        }

        $amountSum = $tenorData->sum('amount');
        if ($amountSum != $request->amount) {
            return [
                'success' => false,
                'message' => 'Incorrect amount.'
            ];
        }

        $noSuratPermohonan = $mltHeader->no_surat_permohonan;
        $idMultiguna = $tenorData->pluck('id_kredit_usaha_transaction')[0];
        $tenorSequence = $tenorData->pluck('tenor_sequence')[0];
        $invoiceScope = count($tenorData) > 1 ? 'Merge Payment' : ($tenorSequence == 0 ? 'Full Payment' : 'Split');
        $invoiceHeaderData = $this->repository->invoiceHeaderData([
            'trx_no' => $request->trx_no,
            'debitur_trx_id' => $idMultiguna,
            'invoice_scope' => $invoiceScope,
            'total_amount' => $amountSum,
            'status' => 'Paid',
            'is_manual' => 1,
            'tenor_sequence' => $tenorSequence
        ]);

        $newInvoiceId = $invoiceHeaderData->invoice_id;
        $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

        $this->repository->createKonstruksiPaymentGateway([
            'invoice_id' => $newInvoiceId,
            'status' => 'Paid',
            'payment_amount_ijp' => $amountSum,
            'order_id' => $orderId
        ]);

        // $debugFileName = 'ORDERID-pembayaran-mlt' . '.' . $debugFile->getClientOriginalExtension();
        $attachmentBuktiBayar = $request->file('file');
        $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));
        $ext = $attachmentBuktiBayar->getClientOriginalExtension();
        $fn = $orderId . '-pembayaran-kkpbj';

        $debiturPayload = $tenorData->map(function ($itemDebitur) {
            return [
                'no_sp_detail' => $itemDebitur->no_sp_detail,
                'invoice_number' => $itemDebitur->invoice_number,
                'total_amount' => $itemDebitur->amount
            ];
        })->toArray();
        // $creatioPayload = [
        //     'NoSuratPermohonan' => $noSuratPermohonan,
        //     'ListDebitur' => $debiturPayload,
        //     'NamaFile' => $fn . '.' . $ext,
        //     'DataBase64' => $fileBase64
        // ];
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
        // dd($creatioPayload);
        $localStackPath = $attachmentBuktiBayar->storeAs(
            'uploads/penjaminan/payment-multiguna',
            $fn . '.' . $ext,
            's3'
        );

        $this->repository->createLampiranPembayaran([
            'trx_no' => $request->trx_no,
            'lampiran_id' => 'pembayaran',
            'file_name' => $fn,
            'status_doc' => 'N',
            'version' => 1,
            'mime_type' => $attachmentBuktiBayar->getMimeType(),
            'file_info' => $localStackPath
        ]);

        DB::commit();

        return [
            'success' => true,
            'message' => 'Bukti bayar manual successfully uploaded.',
            'status' => 200,
        ];
    }
}
