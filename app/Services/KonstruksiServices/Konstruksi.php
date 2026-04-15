<?php

namespace App\Services\KonstruksiServices;

use App\Helper\ValidateDebitur;
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

    public function getDetail($trx_no, $no_surat_permohonan)
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
            if ($rows) {
                $this->decryptInstitution($rows, $key);
            }
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

    public function store($request, $user, string $mitraAlias, array $penjaminanPKSData)
    {
        //
        if (empty($request->allFiles()) && $request->data['trx_status'] != 'D') {
            return response()->json([
                'success' => false,
                'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
            ], 422);
        }

        $selectedPks = $request->data['selectedPks'];
        $dataDebitur = $request->input('data.dataDebitur', []);

        $result = ValidateDebitur::validateDebiturBatch(['selectedPks' => $selectedPks, 'penjaminanPKSData' => $penjaminanPKSData, 'dataDebitur' => $dataDebitur]);
        $dataDebitur = $result['dataDebitur'];
        if (!$result['success']) {
            return response()->json($result, 422);
        }

        DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias) {
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
            $mitraId = $request->data['mitra_id'];
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
                ->map(function ($value) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey) {
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
                        'mitra_id' => 'MDR',
                        'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
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

            $this->repository->insertLampiranDetails($savedAttachments);

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
    }
    public function update($request, $user, string $mitraAlias, array $penjaminanPKSData, $trxNo)
    {
        $tenant_ID = '';
        $newStatus = $request->data['trx_status'];
        $dataDebitur = $request->input('data.dataDebitur', []);
        if ($request->data['trx_status'] !== 'D' && !empty($dataDebitur)) {
            $selectedPks = $request->data['selectedPks'] ?? $request->data['pks'];
            $result = ValidateDebitur::validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return response()->json($result, 422);
            }
        }
        if ($newStatus === 'D') {
            if (!empty($request->allFiles())) {
                return response()->json([
                    'success' => false,
                    'message' => 'File upload tidak diperbolehkan saat Save as Draft.',
                ], 422);
            }
        } else {
            if (empty($request->allFiles())) {
                return response()->json([
                    'success' => false,
                    'message' => 'File upload wajib diisi saat Submit.',
                ], 422);
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
                            'mitra_id' => 'MDR',
                            'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
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
}
