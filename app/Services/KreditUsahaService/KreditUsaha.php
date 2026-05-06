<?php

namespace App\Services\KreditUsahaService;

use App\Helpers\ValidateDebitur;
use App\Helpers\AesHelper;
use App\Models\PenjaminanLampiranDtl;
use App\Repositories\KreditUsahaRepository;
use App\Services\CreatioService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KreditUsaha
{
    public function __construct(protected KreditUsahaRepository $repository) {}

    public function getDetail($id)
    {
        $trx_no = $id;
        $penjaminanDetail = $this->repository->getPenjaminanTransaction($trx_no);

        if (!$penjaminanDetail) {
            return null;
        }

        $rows = $this->repository->getInstitution($penjaminanDetail->id_kredit_usaha_transaction);

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

    public function store($request, $user, string $mitraAlias, array $penjaminanPKSData, $tenant_ID)
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

            $penjaminanTransaction = $this->repository->createPenjaminanTransaction([
                'trx_no' => $trxNo,
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->data['trx_status'],
                'status_sync_creatio' => 0,
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'product' => 'ku',
                'mitra_id' => $mitraAlias,
                'no_rek' => '012312'
            ]);

            $kreditUsaha = $this->repository->createKUTransaction([
                'trx_no' => $trxNo,
                // 'jenis_product' => $request->['jenisBond'],
                'jenis_product_description' => 'Kredit Usaha',
                'pks_number' => $request->data['pks'],
                'fee_base_number' => $request->data['feeBasePercentage'],
                'fee_base_percentage' => $request->data['feeBasePercentage'],
                'bank_name' => $request->data['bankCabang'],
                'bank_code' => $request->data['bank'],
                'text_certified' => $request->data['teksPenjaminanSp'],
                'created_at' => $nowJakarta,
            ]);

            $kreditUsahaId = $kreditUsaha->getKey();
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

            $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value, $idx) use ($mitraAlias, $nowJakarta, &$institutionMap, &$user, $key, $hashKey, $tenant_ID) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();
                    $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                    $institutionMap[$idx] = $instId;
                    // dd($value);
                    return [
                        'category' => 'P',
                        'mitra_id' => $mitraAlias,
                        'tenant_id' => $tenant_ID,
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
                        'created_at' => $nowJakarta,
                    ];
                })->values()
                ->all();
            $this->repository->insertInstitutions($rowInstitutions);

            $countDebitur = count($dataDebitur);
            $rows = collect($dataDebitur)
                ->pluck('debitur_multiguna')
                ->filter()
                ->map(function (array $d, int $idx) use ($request, $kreditUsahaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $spSequence = $idx + 1;
                    $baseSp = $request->data['noSuratPermohonan'];
                    $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                    return [
                        'kredit_usaha_trx_id' => $kreditUsahaId,
                        'nama_nasabah' => $d['debitur_name'] ?? null,
                        'alamat_nasabah' => $d['debitur_address'] ?? null,

                        // Gak ada 
                        'instansi' => $d['instansi'] ?? null,
                        'suku_bunga' => $d['suku_bunga'] ?? null,
                        'jenis_kredit' => $d['jenis_kredit'],
                        'sp3' => $d['sp3'] ?? null,
                        'npwp_principal' => $d['npwp_principal'] ?? null,
                        'limit_penarikan' => $d['limit_penarikan'] ?? null,


                        // Ganti nama
                        'penggunaan_kredit' => $d['penggunaan_kredit'] ?? 0,
                        'plafond_kredit' => $d['plafond_kredit'] ?? null,
                        'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
                        'tanggal_usia' => $d['tanggal_usia'] ?? null,
                        'jangka_waktu' => $d['jangka_waktu'] ?? null,
                        'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
                        'ijp' => $d['ijp'] ?? null,

                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $jatuhTempo->toDateString() ?? null,
                        'jenis_agunan' => $d['jenis_agunan'] ?? null,
                        'nilai_agunan' => $d['nilai_agunan'] ?? null,
                        'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                        'loan_number' => $loanNumber,
                        'base_plafond' => $d['plafond_kredit'] ?? null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        'institution_id' => $institutionMap[$idx] ?? null,
                        'status_debitur' => $d['status_debitur'],
                        'jenis_penjaminan' => $d['jenis_penjaminan'],
                        'created_at' => $nowJakarta,

                        // 'nik' => $enc($d['nik']) ?? null,
                        // 'plafond_pembiayaan' => $d['plafond_pembiayaan_rp'] ?? 0,
                        // 'plafond_max_debitur' => $d['plafond_max_pembiayaan'] ?? 0,
                        // 'tanggal_jatuh_tempo' => $tglAkhir->toDateString() ?? null,
                        // 'margin' => $d['marginbagi_hasilujrah_thn'] ?? 0,
                        // 'status_debitur' => $d['status_debitur'],
                        // 'nilai_plafon_maksimal'=>$d[]
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertTrxDebiturDefaultBase($rows);
            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);
            $savedAttachments = [];
            $docList = DB::table('setting_hdr as a')
                ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
                ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
                ->select(DB::raw('UPPER(c.value) as value'), 'c.label', 'a.mitra_id', 'a.module', 'c.option2')
                ->where('a.module', 'PENJAMINAN_SETTINGS')
                ->where('b.product_id', 'mlt')
                ->where('a.mitra_id', 'MDR')
                ->where('b.is_mandatory', 1)
                ->where('c.key', 'lampiran')
                ->whereNotNull('b.lampiran')
                ->orderBy('c.value', 'asc')
                ->get();

            foreach ($debiturFiles as $idx => $attachments) {
                $nik =  data_get($debiturInputs, "{$idx}.debitur_multiguna.nik");

                foreach ($attachments as $fileKey => $fileOrArray) {
                    if (is_array($fileOrArray)) {
                        foreach ($fileOrArray as $innerKey => $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $unique = uniqid();
                                $fn = "{$nik}-{$innerKey}-ku";
                                $path = $file->storeAs(
                                    'uploads/penjaminan/kredit-usaha',
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
                            $fn = "{$trxNo}-ktp-mlt-{$idx}-{$fileKey}";
                            $path = $file->storeAs(
                                'uploads/penjaminan/kredit-usaha',
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

    public function update($request, $user, string $mitraAlias, array $penjaminanPKSData, $trxNo, $newStatus)
    {
        $isDraft = $newStatus === 'D';
        $penjaminanTrx = $this->repository->checkPenjaminanTransaction($trxNo);
        if (!$penjaminanTrx) {
            return [
                'success' => false,
                'message' => 'Transaksi tidak ditemukan.'
            ];
        }

        if ($penjaminanTrx->trx_status !== 'D') {
            return [
                'success' => false,
                'message' => 'Transaksi tidak dapat diubah karena status bukan Draft.'
            ];
        }

        // Validasi debitur batch hanya saat submit
        $dataDebitur = [];
        if (!$isDraft) {
            $selectedPks = $request->data['selectedPks'];
            $dataDebitur = $request->input('data.dataDebitur', []);
            $result = ValidateDebitur::validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return $result;
            }
        }

        DB::transaction(function () use ($request, $user, $trxNo, $penjaminanTrx, $isDraft, $dataDebitur, $mitraAlias) {
            $nowJakarta     = Carbon::now('Asia/Jakarta');
            $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
            $spSplit        = $request->boolean('data.spSplit');

            // Update PenjaminanTransaction
            $this->repository->updatePenjaminanTransaction($trxNo, [
                'sp_split'                 => $spSplit,
                'no_surat_permohonan'      => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status'               => $isDraft ? 'D' : 'NA',
                'updated_at'               => $nowJakarta,
            ]);

            $kreditUsahaTrx = $this->repository->updateKreditUsahaTransaction($trxNo, [
                'jenis_product_description' => $request->data['jenisProduk'],
                'pks_number'                => $request->data['pks'],
                'fee_base_number'           => $request->data['feeBasePercentage'],
                'fee_base_percentage'       => $request->data['feeBasePercentage'],
                'bank_name'                 => $request->data['bankCabang'],
                'bank_code'                 => $request->data['bank'],
                'text_certified'            => $request->data['teksPenjaminanSp'],
                'updated_at'                => $nowJakarta,
            ]);
            // Proses tambahan hanya saat submit
            if (!$isDraft) {

                $kreditUsahaTrxId = $kreditUsahaTrx->getKey();
                $key      = base64_decode(config('services.secure.key'));
                $hashKey  = config('services.secure.hash_key');

                $mitraId  = $request->data['mitra_id'];
                $currentYear = date('Y');
                $prefix   = $mitraId . $currentYear;

                $lastLoan = $this->repository->checkTrxDebiturDefaultBase($prefix);
                $startSeq = $lastLoan ? ((int) substr($lastLoan, -4) + 1) : 1;

                // Insert Institution
                $institutionMap  = [];
                $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                    ->pluck('institution_data')
                    ->filter()
                    ->map(function ($value) use ($nowJakarta, &$institutionMap, $user, $key, $hashKey) {
                        $enc    = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                        $nik    = $value['id_number'] ?? null;
                        $instId = (string) Str::uuid();

                        if ($nik) {
                            $institutionMap[$nik] = $instId;
                        }

                        return [
                            'category'                => 'P',
                            'mitra_id'                => 'MDR',
                            'tenant_id'               => '2185e11e-35a6-4c89-aa3f-4645451e0536',
                            'id_issued_location'      => '-',
                            'id_add_issued_location'  => '-',
                            'id_add_type'             => '-',
                            'created_by'              => $user->user_id,
                            'full_name'               => $value['full_name'] ?? null,
                            'home_province'           => $value['home_province'] ?? null,
                            'home_city'               => $value['home_city'] ?? 0,
                            'home_district'           => $value['home_district'] ?? null,
                            'home_sub_district'       => $value['home_sub_district'] ?? null,
                            'home_zipcode'            => $value['home_zipcode'] ?? null,
                            'birth_place'             => $value['birth_place'] ?? null,
                            'birth_date'              => $enc($value['birth_date'] ?? null),
                            'gender'                  => $value['gender'] ?? null,
                            'id_type'                 => $value['id_type'] ?? null,
                            'id_number'               => $enc($nik),
                            'id_number_hash'          => $nik ? hash_hmac('sha256', $nik, $hashKey) : null,
                            'job_id'                  => $value['job_id'] ?? null,
                            'job_level'               => $value['job_level'] ?? null,
                            'job_employer_name'       => $value['job_employer_name'] ?? null,
                            'job_start_date'          => $value['job_start_date'] ?? null,
                            'job_industry_type'       => $value['job_industry_type'] ?? null,
                            'current_salary_amount'   => $enc($value['current_salary_amount'] ?? null),
                            'phone_1'                 => $enc($value['phone_1'] ?? null),
                            'email_1'                 => $enc($value['email_1'] ?? null),
                            'tax_id'                  => $enc($value['npwp'] ?? null),
                            'current_salary_currency' => $value['current_salary_currency'] ?? null,
                            'tax_type'                => 'npwp',
                            'institution_id'          => $instId,
                            'created_at'              => $nowJakarta,
                        ];
                    })
                    ->values()
                    ->all();
                // if (!empty($rowInstitutions)) {
                //     Institution::insert($rowInstitutions);
                // }
                $this->repository->insertInstitutions($rowInstitutions);

                // Insert TrxDebiturKpr
                $countDebitur = count($dataDebitur);
                $rows = collect($dataDebitur)
                    ->pluck('debitur_multiguna')
                    ->filter()
                    ->map(function (array $d, int $idx) use ($request, $kreditUsahaTrxId, $nowJakarta, $prefix, $startSeq, $institutionMap, $countDebitur, $user) {
                        $spSequence        = $idx + 1;
                        $baseSp            = $request->data['noSuratPermohonan'];
                        $seq               = $startSeq + $idx;
                        $loanNumber        = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                        $nik               = $d['nik'] ?? null;

                        $nama_nasabah      = $d['nama_nasabah']      ?? $d['debitur_name']            ?? null;
                        $alamat_nasabah    = $d['alamat_nasabah']    ?? $d['debitur_address']          ?? null;
                        $penggunaan_kredit = $d['penggunaan_kredit'] ?? $d['penggunaan_pembiayaan']    ?? null;
                        $plafond_kredit    = $d['plafond_kredit']    ?? $d['plafond_pembiayaan_rp']    ?? 0;
                        $nilai_penjaminan  = $d['nilai_penjaminan']  ?? $d['nilai_kafalah']            ?? 0;
                        $tanggal_usia      = $d['tanggal_usia']      ?? $d['tgl_lahir']                ?? null;
                        $suku_bunga        = $d['suku_bunga']        ?? $d['marginbagi_hasilujrah_thn'] ?? 0;
                        $jangka_waktu      = $d['jangka_waktu']      ?? $d['jw_bulan']                 ?? null;
                        $ijp               = $d['ijp']               ?? $d['ijk']                      ?? 0;

                        return [
                            'kredit_usaha_trx_id' => $kreditUsahaTrxId,
                            'nama_nasabah'        => $nama_nasabah,
                            'alamat_nasabah'      => $alamat_nasabah,
                            'no_sp_detail'        => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                            'penggunaan_kredit'   => $penggunaan_kredit,
                            'plafond_kredit'      => $plafond_kredit,
                            'nilai_penjaminan'    => $nilai_penjaminan,
                            'tanggal_usia'        => $tanggal_usia,
                            'instansi'            => $d['instansi']            ?? null,
                            'suku_bunga'          => $suku_bunga,
                            'jangka_waktu'        => $jangka_waktu,
                            'tanggal_realisasi'   => $d['tanggal_realisasi']   ?? null,
                            'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                            'jenis_agunan'        => $d['jenis_agunan']        ?? null,
                            'nilai_agunan'        => $d['nilai_agunan']        ?? 0,
                            'tenaga_kerja'        => $d['tenaga_kerja']        ?? null,
                            'jenis_terjamin'      => $d['jenis_terjamin']      ?? null,
                            'ijp'                 => $ijp,
                            'loan_number'         => $loanNumber,
                            'no_sp_core_debitur'  => $d['no_sp_core_debitur'] ?? null,
                            'institution_id'      => $institutionMap[$nik] ?? null,
                            // 'created_by'          => $user->id,
                            'created_at'          => $nowJakarta,
                            'jenis_penjaminan'    => $d['jenis_penjaminan']    ?? null,
                            'status_debitur'      => $d['status_debitur']      ?? null,
                        ];
                    })
                    ->values()
                    ->all();

                // if (!empty($rows)) {
                //     TrxDebiturDefaultBase::insert($rows);
                // }
                $this->repository->insertTrxDebiturDefaultBase($rows);

                // Upload file
                $allFiles          = $request->allFiles();
                $debiturFiles      = data_get($allFiles, 'data.dataDebitur', []);
                $institutionInputs = data_get($request->data, 'dataInstitution', []);
                $savedAttachments  = [];

                foreach ($debiturFiles as $idx => $attachments) {
                    $nik = data_get($institutionInputs, "{$idx}.institution_data.id_number")
                        ?? 'UNKNOWN_ID';

                    foreach ($attachments as $fileKey => $fileOrArray) {
                        if (is_array($fileOrArray)) {
                            foreach ($fileOrArray as $innerKey => $file) {
                                if ($file instanceof \Illuminate\Http\UploadedFile) {
                                    $ext  = $file->getClientOriginalExtension();
                                    $fn   = "{$nik}-{$innerKey}";
                                    $path = $file->storeAs(
                                        'uploads/penjaminan/kredit-usaha',
                                        $fn . '.' . $ext,
                                        's3'
                                    );
                                    $savedAttachments[] = [
                                        'trx_no'      => $trxNo,
                                        'lampiran_id' => $innerKey,
                                        'file_name'   => $fn,
                                        'status_doc'  => 'N',
                                        'version'     => 1,
                                        'mime_type'   => $file->getMimeType(),
                                        'file_info'   => $path,
                                        'created_at'  => $nowJakarta,
                                    ];
                                }
                            }
                        } else {
                            if ($fileOrArray instanceof \Illuminate\Http\UploadedFile) {
                                $ext  = $fileOrArray->getClientOriginalExtension();
                                $fn   = "{$trxNo}-ktp-kpr-{$idx}-{$fileKey}";
                                $path = $fileOrArray->storeAs(
                                    'uploads/penjaminan/kpr',
                                    $fn . '.' . $ext,
                                    's3'
                                );
                                $savedAttachments[] = [
                                    'trx_no'      => $trxNo,
                                    'lampiran_id' => $fileKey,
                                    'file_name'   => $fn,
                                    'status_doc'  => 'N',
                                    'version'     => 1,
                                    'mime_type'   => $fileOrArray->getMimeType(),
                                    'file_info'   => $path,
                                    'created_at'  => $nowJakarta,
                                ];
                            }
                        }
                    }
                }

                // if (!empty($savedAttachments)) {
                //     DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                // }
                $this->repository->insertLampiranDetails($savedAttachments);

                // Insert PenjaminanFlow
                // PenjaminanFlow::create([
                //     'trx_no'          => $trxNo,
                //     'trx_status'      => 'NA',
                //     // 'created_at'      => $nowJakarta,
                //     'created_at' => now(),
                //     'created_by_id'   => $user->user_id,
                //     'created_by_name' => $user->name,
                //     'updated_at'      => null,
                // ]);
                $this->repository->createPenjaminanFlow([
                    'trx_no'          => $trxNo,
                    'trx_status'      => 'NA',
                    // 'created_at'      => $nowJakarta,
                    'created_at' => now(),
                    'created_by_id'   => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at'      => null,
                ]);
            }
        });
    }

    public function GetDetailPaymentKreditUsaha($request)
    {
        $key = base64_decode(config('services.secure.key'));

        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $data = [];
        $dataHeader = $this->repository->getHeader($trx_no, $no_surat_permohonan, $isSplit);

        if (!$dataHeader) {
            return ['success' => false, 'message' => 'Data tidak ditemukan'];
        }
        $dataHeader->each(function ($row) use ($key) {
            $decryptedNik = AesHelper::decrypt($row->nik, $key);

            $row->nik = $decryptedNik;
        });

        $dataUnpaid = $this->repository->getUnpaid($trx_no);

        $data = [
            'dataHeader' =>
            [
                'data_pending' => $dataHeader,
                'data_unpaid' => $dataUnpaid
            ]
        ];

        return $data;
    }

    public function GetDetailListPaymentKreditUsaha($request)
    {
        $no_surat_permohonan = $request->query('no_surat_permohonan');
        $trx_no              = $request->query('trx_no');
        $isSplit             = (int) $request->query('is_split', null);
        $dataHeader = $this->repository->getHeaderList($trx_no, $no_surat_permohonan, $isSplit);

        if (!$dataHeader) {
            return ['success' => false, 'message' => 'Data tidak ditemukan'];
        }

        $dataDebitur = $this->repository->dataDebitur($dataHeader->id_kredit_usaha_transaction);

        $debiturById = $dataDebitur->keyBy('id_trx_debitur');
        $debiturIds  = $dataDebitur->pluck('id_trx_debitur')->filter()->unique()->values();
        if ($debiturIds->isEmpty()) {
            return ['data' => []];
        }
        $schedules = $this->repository->schedules($debiturIds);

        $schedulesUnpaid = $this->repository->scheduleUnpaid($debiturIds);

        $result = $schedules
            ->groupBy('tenor_sequence')
            ->map(function ($rows, $tenor) use ($debiturById, $schedulesUnpaid) {
                $scheduleByDebitur = $rows->keyBy('id_trx_debitur');
                $unpaidSchedules = $schedulesUnpaid->where('tenor_sequence', $tenor);
                $listPending = $rows->where('status', 'Pending')->pluck('id_trx_debitur')->unique()->values()
                    ->map(function ($id) use ($debiturById, $scheduleByDebitur) {
                        $d = $debiturById->get($id);
                        if (!$d) return null;
                        $sch = $scheduleByDebitur->get($id);
                        return [
                            'id_trx_debitur'    => $d->id_trx_debitur,
                            'no_sp_detail'      => $d->no_sp_detail,
                            'loan_number'       => $d->loan_number,
                            'nik'               => $d->nik,
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

        // dd($tenorData);
        $mltHeader = $this->repository->mltHeader($request->trx_no);

        if (count($tenorData) < 1 || !$mltHeader) {
            return [
                'success' => false,
                'message' => 'Penjaminan multiguna not found.'
            ];
        }
        // dd($tenorData->sum('amount'));
        $amountSum = $tenorData->sum('amount');
        if ($amountSum != $request->amount) {
            return [
                'success' => false,
                'message' => 'Incorrect amount.'
            ];
        }

        // $collectTenorDebitur = collect($tenorData)->toArray();
        // dd($collectTenorDebitur);

        $noSuratPermohonan = $mltHeader->no_surat_permohonan;
        $idMultiguna = $tenorData->pluck('id_kredit_usaha_transaction')[0];
        $tenorSequence = $tenorData->pluck('tenor_sequence')[0];
        $invoiceScope = count($tenorData) > 1 ? 'Merge Payment' : ($tenorSequence == 0 ? 'Full Payment' : 'Split');
        $invoiceHeaderData = $this->repository->createDebiturInvoiceHeader([
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

        $this->repository->createDebiturPaymentGateway([
            'invoice_id' => $newInvoiceId,
            'status' => 'Paid',
            'payment_amount_ijp' => $amountSum,
            'order_id' => $orderId
        ]);

        // dd($newInvoiceId);
        foreach ($tenorData as $tenorDebitur) {
            // MultigunaInvoiceFullPayment::create([
            //     'invoice_id' => $newInvoiceId,
            //     'id_trx_debitur' => $tenorDebitur->id_trx_debitur,
            //     'amount' => $tenorDebitur->amount
            // ]);
            $this->repository->updateDebiturTenorSchedule($tenorDebitur->schedule_id, [
                'invoice_id' => $newInvoiceId,
                'status' => 'Paid'
            ]);
        }

        // $debugFileName = 'ORDERID-pembayaran-mlt' . '.' . $debugFile->getClientOriginalExtension();
        $attachmentBuktiBayar = $request->file('file');
        $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));
        $ext = $attachmentBuktiBayar->getClientOriginalExtension();
        $fn = $orderId . '-pembayaran-ku';

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
        $svcCreatio = new CreatioService();
        $response = $svcCreatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', $creatioPayload);
        if ($response->status() !== 200) {
            throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
        }
        $bodyResponse = json_decode($response->body(), true);
        if ($bodyResponse['Success'] !== true) {
            throw new Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
        }
        // (END) pending core API ready
        // dd($creatioPayload);
        $localStackPath = $attachmentBuktiBayar->storeAs(
            'uploads/penjaminan/payment-multiguna',
            $fn . '.' . $ext,
            's3'
        );
        $this->repository->createPenjaminanLampiranDtl([
            'trx_no' => $request->trx_no,
            'lampiran_id' => 'pembayaran',
            'file_name' => $fn,
            'status_doc' => 'N',
            'version' => 1,
            'mime_type' => $attachmentBuktiBayar->getMimeType(),
            'file_info' => $localStackPath
        ]);

        DB::commit();
    }
}
