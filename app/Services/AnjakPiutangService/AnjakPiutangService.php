<?php

namespace App\Services\AnjakPiutangService;

use App\Helper\AesHelper;
use App\Models\TenantMitra;
use App\Models\PenjaminanTransaction;
use App\Models\v2\MultigunaTrxAjpModel;
use App\Repositories\AnjakPiutangRepository;
use App\Services\CreatioService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AnjakPiutangService
{
    public function __construct(
        protected AnjakPiutangRepository $repository
    ) {}

    public function getAjpDetailWithAttachments(string $trxNo)
    {
        $penjaminanDetail = $this->repository->getAjpDetail($trxNo);

        if (!$penjaminanDetail) {
            throw new Exception('Data not found.');
        }

        $rows = $this->repository->getAjpDebitur((int) $penjaminanDetail->id_multiguna_ajp);
        $lampiran = $this->repository->getAjpLampiran($trxNo);

        if ($rows->isNotEmpty()) {
            $key = base64_decode(config('services.secure.key'));

            foreach ($rows as $row) {
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

            foreach ($lampiran as $att) {
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
                            'presigned_url' => !empty($att->file_info)
                                ? Storage::disk('s3')->temporaryUrl($att->file_info, now()->addMinutes(15))
                                : null,
                        ];

                        $row->attachments[] = $item;
                    }
                }
            }
        }

        $ajpFlow = $this->repository->getAjpFlow($trxNo);

        if ($ajpFlow->isNotEmpty()) {
            $penjaminanDetail->flowAjp = $ajpFlow;
        }

        if ($rows->isNotEmpty()) {
            $penjaminanDetail->debiturAjp = $rows;
        }

        return $penjaminanDetail;
    }

    public function storeAjp(Request $request, object $user): array
    {
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return [
                'success' => false,
                'message' => 'Tenant mitra data not found.',
                'status' => 404,
            ];
        }

        validator($request->all(), [
            'data.noSuratPermohonan' => 'required|string',
            'data.pks' => 'required|string',
            'data.selectedPks' => 'nullable|string',
            'data.jenisProduk' => 'required|string',
            'data.bank' => 'required|string',
            'data.tglSuratPermohonan' => 'required|date',
            'data.spSplit' => 'required',
            'data.bankCabang' => 'nullable|string',
            'data.feeBasePercentage' => 'nullable|numeric',
            'data.teksPenjaminanSp' => 'nullable|string',
            'data.mitra_id' => 'required|string',
            'data.trx_status' => 'required|string',
            'data.dataDebitur' => 'nullable|array',
            'data.dataDebitur.*.attachments' => 'nullable|array',
            'data.dataDebitur.*.attachments.nik' => 'nullable|string',
            'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
            'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
            'data.dataInstitution' => 'nullable|array',
        ])->validate();

        $penjaminanPKSData = $this->getPenjaminanPKSData($user);
        if (($penjaminanPKSData['Success'] ?? false) !== true) {
            return [
                'success' => false,
                'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data',
                'status' => 500,
            ];
        }

        $isDraft = $request->input('data.trx_status') === 'D';
        if ($isDraft) {
            if (!empty($request->allFiles())) {
                return [
                    'success' => false,
                    'message' => 'File upload tidak diperbolehkan saat Save as Draft.',
                    'status' => 422,
                ];
            }
        } elseif (empty($request->allFiles())) {
            return [
                'success' => false,
                'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                'status' => 422,
            ];
        }

        $dataDebitur = $request->input('data.dataDebitur', []);
        if (!$isDraft) {
            $selectedPks = $request->input('data.selectedPks', $request->input('data.pks'));
            $result = $this->validateDebiturBatchAjp($selectedPks, $penjaminanPKSData, $dataDebitur);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Terdapat Data Debitur yang tidak sesuai',
                    'list_debitur' => $result['list_debitur'] ?? [],
                    'status' => 422,
                ];
            }

            $dataDebitur = $result['dataDebitur'];
        }

        DB::transaction(function () use ($request, $user, $dataDebitur, $tenantMitraData, $isDraft) {
            $currentYear = date('Y');
            $currentMonth = date('m');

            $lastTrx = PenjaminanTransaction::lockForUpdate()
                ->where('trx_no', 'like', 'PNJ-' . $currentYear . '-' . $currentMonth . '%')
                ->orderBy('trx_no', 'desc')
                ->value('trx_no');

            $nextSeq = $lastTrx ? (intval(substr($lastTrx, -4)) + 1) : 1;
            $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

            $permohonanDate = Carbon::parse($request->input('data.tglSuratPermohonan'))->format('Y-m-d');
            $nowJakarta = $this->repository->getNowJakarta();
            $spSplit = $request->boolean('data.spSplit');

            PenjaminanTransaction::create([
                'trx_no' => $trxNo,
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $request->input('data.noSuratPermohonan'),
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->input('data.trx_status'),
                'status_sync_creatio' => 0,
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'product' => 'ajp',
                'mitra_id' => $tenantMitraData->alias,
                'no_rek' => '012312',
            ]);

            $ajpTrx = MultigunaTrxAjpModel::create([
                'trx_no' => $trxNo,
                'jenis_product' => $request->input('data.jenisProduk'),
                'jenis_product_description' => 'ajp',
                'pks_number' => $request->input('data.pks'),
                'fee_base_number' => $request->input('data.feeBasePercentage'),
                'fee_base_percentage' => $request->input('data.feeBasePercentage'),
                'bank_name' => $request->input('data.bankCabang'),
                'bank_code' => $request->input('data.bank'),
                'text_certified' => $request->input('data.teksPenjaminanSp'),
                'created_by' => $user->id,
                'created_at' => $nowJakarta,
            ]);

            $ajpTrxId = $ajpTrx->getKey();

            if ($isDraft) {
                $this->repository->createPenjaminanFlow([
                    'trx_no' => $trxNo,
                    'trx_status' => 'D',
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null,
                ]);

                return;
            }

            $mitraId = $request->input('data.mitra_id');
            $prefix = $mitraId . $currentYear;

            $lastLoan = $this->repository->getLatestLoanNumberByPrefix($prefix);
            $startSeq = $lastLoan ? ((int) substr($lastLoan, -4) + 1) : 1;

            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');
            $institutionMap = [];

            $rowInstitutions = collect(data_get($request->all(), 'data.dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value) use ($nowJakarta, &$institutionMap, $user, $key, $hashKey, $tenantMitraData) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();

                    if ($nik) {
                        $institutionMap[$nik] = $instId;
                    }

                    return [
                        'category' => 'P',
                        'mitra_id' => $tenantMitraData->alias,
                        'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
                        'id_issued_location' => '-',
                        'id_add_issued_location' => '-',
                        'id_add_type' => '-',
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
                        'id_number_hash' => $nik ? hash_hmac('sha256', $nik, $hashKey) : null,
                        'job_id' => $value['job_id'] ?? null,
                        'job_level' => $value['job_level'] ?? null,
                        'job_employer_name' => $value['job_employer_name'] ?? null,
                        'job_start_date' => $value['job_start_date'] ?? null,
                        'job_industry_type' => $value['job_industry_type'] ?? null,
                        'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
                        'phone_1' => $enc($value['phone_1'] ?? null),
                        'email_1' => $enc($value['email_1'] ?? null),
                        'tax_id' => $enc($value['npwp'] ?? null),
                        'current_salary_currency' => $value['current_salary_currency'] ?? null,
                        'tax_type' => 'npwp',
                        'institution_id' => $instId,
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertInstitutions($rowInstitutions);

            $countDebitur = count($dataDebitur);
            $rows = collect($dataDebitur)
                ->pluck('debitur_ajp')
                ->filter()
                ->map(function (array $d, int $idx) use ($request, $ajpTrxId, $nowJakarta, $prefix, $startSeq, $institutionMap, $countDebitur, $user) {
                    $spSequence = $idx + 1;
                    $baseSp = $request->input('data.noSuratPermohonan');
                    $seq = $startSeq + $idx;
                    $loanNumber = $d['loan_number'] ?? ($prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT));
                    $nik = $d['id_number'] ?? null;

                    return [
                        'id_multiguna_ajp' => $ajpTrxId,
                        'no_urut' => $d['no_urut'] ?? $spSequence,
                        'nama_nasabah' => $d['nama_nasabah'] ?? null,
                        'alamat_nasabah' => $d['alamat_nasabah'] ?? null,
                        'no_invoice' => $d['no_invoice'] ?? null,
                        'tanggal_invoice' => $d['tanggal_invoice'] ?? null,
                        'tanggal_jatuh_tempo_invoice' => $d['tanggal_jatuh_tempo_invoice'] ?? null,
                        'nilai_invoice' => $d['nilai_invoice'] ?? 0,
                        'nama_payor' => $d['nama_payor'] ?? null,
                        'jenis_payor' => $d['jenis_payor'] ?? null,
                        'no_perjanjian_pembayaran' => $d['no_perjanjian_pembayaran'] ?? null,
                        'tanggal_perjanjian_pembayaran' => $d['tanggal_perjanjian_pembayaran'] ?? null,
                        'penggunaan_kredit' => $d['penggunaan_kredit'] ?? null,
                        'plafond_kredit' => $d['plafond_kredit'] ?? 0,
                        'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
                        'jangka_waktu' => $d['jangka_waktu'] ?? null,
                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                        'jenis_agunan' => $d['jenis_agunan'] ?? null,
                        'nilai_agunan' => $d['nilai_agunan'] ?? 0,
                        'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                        'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
                        'ijp' => $d['ijp'] ?? 0,
                        'loan_number' => $loanNumber,
                        'catatan' => $d['catatan'] ?? null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        'no_sp_core_debitur' => $d['no_sp_core_debitur'] ?? null,
                        'institution_id' => $institutionMap[$nik] ?? null,
                        'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
                        'status_debitur' => $d['status_debitur'] ?? null,
                        'created_by' => $user->id,
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertDebitur($rows);

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $savedAttachments = [];
            $institutionInputs = data_get($request->all(), 'data.dataInstitution', []);

            foreach ($debiturFiles as $idx => $attachments) {
                $nik = data_get($institutionInputs, "{$idx}.institution_data.id_number") ?? 'UNKNOWN_ID';

                foreach ($attachments as $fileKey => $fileOrArray) {
                    if (is_array($fileOrArray)) {
                        foreach ($fileOrArray as $innerKey => $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $fn = "{$nik}-{$innerKey}-ajp";
                                $path = $file->storeAs('uploads/penjaminan/ajp', $fn . '.' . $ext, 's3');

                                $savedAttachments[] = [
                                    'trx_no' => $trxNo,
                                    'lampiran_id' => $innerKey,
                                    'file_name' => $fn,
                                    'status_doc' => 'N',
                                    'version' => 1,
                                    'mime_type' => $file->getMimeType(),
                                    'file_info' => $path,
                                    'created_at' => $nowJakarta,
                                ];
                            }
                        }
                    } elseif ($fileOrArray instanceof \Illuminate\Http\UploadedFile) {
                        $ext = $fileOrArray->getClientOriginalExtension();
                        $fn = "{$trxNo}-ajp-{$idx}-{$fileKey}";
                        $path = $fileOrArray->storeAs('uploads/penjaminan/ajp', $fn . '.' . $ext, 's3');

                        $savedAttachments[] = [
                            'trx_no' => $trxNo,
                            'lampiran_id' => $fileKey,
                            'file_name' => $fn,
                            'status_doc' => 'N',
                            'version' => 1,
                            'mime_type' => $fileOrArray->getMimeType(),
                            'file_info' => $path,
                            'created_at' => $nowJakarta,
                        ];
                    }
                }
            }

            $this->repository->insertLampiranDetails($savedAttachments);

            $this->repository->createPenjaminanFlow([
                'trx_no' => $trxNo,
                'trx_status' => 'NA',
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name,
                'updated_at' => null,
            ]);
        });

        return [
            'success' => true,
            'message' => $isDraft
                ? 'Data AJP berhasil disimpan sebagai draft'
                : 'Data AJP berhasil disubmit',
            'status' => 200,
        ];
    }

    public function updateAjp(Request $request, object $user, string $trxNo): array
    {
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return [
                'success' => false,
                'message' => 'Tenant mitra data not found.',
                'status' => 404,
            ];
        }

        validator($request->all(), [
            'data.noSuratPermohonan' => 'required|string',
            'data.pks' => 'required|string',
            'data.selectedPks' => 'nullable|string',
            'data.jenisProduk' => 'required|string',
            'data.bank' => 'required|string',
            'data.tglSuratPermohonan' => 'required|date',
            'data.spSplit' => 'required|string',
            'data.bankCabang' => 'nullable|string',
            'data.feeBasePercentage' => 'nullable|numeric',
            'data.teksPenjaminanSp' => 'nullable|string',
            'data.dataDebitur' => 'nullable|array',
            'data.dataInstitution' => 'nullable|array',
            'data.tariftarifPercentage' => 'nullable|numeric',
        ])->validate();

        $penjaminanTrx = $this->repository->findPenjaminanForUpdate($trxNo);
        if (!$penjaminanTrx) {
            return [
                'success' => false,
                'message' => 'Transaksi AJP tidak ditemukan.',
                'status' => 404,
            ];
        }

        if ($penjaminanTrx->trx_status !== 'D') {
            return [
                'success' => false,
                'message' => 'Transaksi tidak dapat diubah karena status bukan Draft.',
                'status' => 422,
            ];
        }

        $newStatus = $request->input('data.trx_status');
        $penjaminanPKSData = $this->getPenjaminanPKSData($user);
        if (($penjaminanPKSData['Success'] ?? false) !== true) {
            return [
                'success' => false,
                'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data',
                'status' => 500,
            ];
        }

        if ($newStatus === 'D') {
            if (!empty($request->allFiles())) {
                return [
                    'success' => false,
                    'message' => 'File upload tidak diperbolehkan saat Save as Draft.',
                    'status' => 422,
                ];
            }
        } elseif (empty($request->allFiles())) {
            return [
                'success' => false,
                'message' => 'File upload wajib diisi saat Submit.',
                'status' => 422,
            ];
        }

        $isDraft = $newStatus === 'D';
        $dataDebitur = $isDraft ? [] : $request->input('data.dataDebitur', []);

        if (!$isDraft) {
            $selectedPks = $request->input('data.selectedPks', $request->input('data.pks'));
            $result = $this->validateDebiturBatchAjp($selectedPks, $penjaminanPKSData, $dataDebitur);
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Terdapat Data Debitur yang tidak sesuai',
                    'list_debitur' => $result['list_debitur'] ?? [],
                    'status' => 422,
                ];
            }

            $dataDebitur = $result['dataDebitur'];
        }

        DB::transaction(function () use ($request, $user, $trxNo, $penjaminanTrx, $isDraft, $dataDebitur, $tenantMitraData) {
            $nowJakarta = $this->repository->getNowJakarta();
            $permohonanDate = Carbon::parse($request->input('data.tglSuratPermohonan'))->format('Y-m-d');
            $spSplit = $request->boolean('data.spSplit');

            $this->repository->updatePenjaminanTransaction($penjaminanTrx, [
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $request->input('data.noSuratPermohonan'),
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $isDraft ? 'D' : 'NA',
                'mitra_id' => $tenantMitraData->alias,
                'updated_at' => $nowJakarta,
            ]);

            $ajpTrx = $this->repository->findAjpTransactionForUpdate($trxNo);
            $this->repository->updateAjpTransaction($ajpTrx, [
                'jenis_product' => $request->input('data.jenisProduk'),
                'pks_number' => $request->input('data.pks'),
                'fee_base_number' => $request->input('data.feeBasePercentage'),
                'fee_base_percentage' => $request->input('data.feeBasePercentage'),
                'bank_name' => $request->input('data.bankCabang'),
                'bank_code' => $request->input('data.bank'),
                'text_certified' => $request->input('data.teksPenjaminanSp'),
                'updated_by' => $user->id,
                'updated_at' => $nowJakarta,
            ]);

            if ($isDraft) {
                return;
            }

            $ajpTrxId = $ajpTrx->getKey();
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');

            $mitraId = $request->input('data.mitra_id');
            $currentYear = date('Y');
            $prefix = $mitraId . $currentYear;

            $lastLoan = $this->repository->getLatestLoanNumberByPrefix($prefix);
            $startSeq = $lastLoan ? ((int) substr($lastLoan, -4) + 1) : 1;

            $institutionMap = [];
            $rowInstitutions = collect(data_get($request->all(), 'data.dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value) use ($nowJakarta, &$institutionMap, $user, $key, $hashKey, $tenantMitraData) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();

                    if ($nik) {
                        $institutionMap[$nik] = $instId;
                    }

                    return [
                        'category' => 'P',
                        'mitra_id' => $tenantMitraData->alias,
                        'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
                        'id_issued_location' => '-',
                        'id_add_issued_location' => '-',
                        'id_add_type' => '-',
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
                        'id_number_hash' => $nik ? hash_hmac('sha256', $nik, $hashKey) : null,
                        'job_id' => $value['job_id'] ?? null,
                        'job_level' => $value['job_level'] ?? null,
                        'job_employer_name' => $value['job_employer_name'] ?? null,
                        'job_start_date' => $value['job_start_date'] ?? null,
                        'job_industry_type' => $value['job_industry_type'] ?? null,
                        'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
                        'phone_1' => $enc($value['phone_1'] ?? null),
                        'email_1' => $enc($value['email_1'] ?? null),
                        'tax_id' => $enc($value['npwp'] ?? null),
                        'current_salary_currency' => $value['current_salary_currency'] ?? null,
                        'tax_type' => 'npwp',
                        'institution_id' => $instId,
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertInstitutions($rowInstitutions);

            $countDebitur = count($dataDebitur);
            $rows = collect($dataDebitur)
                ->pluck('debitur_ajp')
                ->filter()
                ->map(function (array $d, int $idx) use ($request, $ajpTrxId, $nowJakarta, $prefix, $startSeq, $institutionMap, $countDebitur, $user) {
                    $spSequence = $idx + 1;
                    $baseSp = $request->input('data.noSuratPermohonan');
                    $seq = $startSeq + $idx;
                    $loanNumber = $d['loan_number'] ?? ($prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT));
                    $nik = $d['id_number'] ?? null;

                    return [
                        'id_multiguna_ajp' => $ajpTrxId,
                        'no_urut' => $d['no_urut'] ?? $spSequence,
                        'nama_nasabah' => $d['nama_nasabah'] ?? null,
                        'alamat_nasabah' => $d['alamat_nasabah'] ?? null,
                        'no_invoice' => $d['no_invoice'] ?? null,
                        'tanggal_invoice' => $d['tanggal_invoice'] ?? null,
                        'tanggal_jatuh_tempo_invoice' => $d['tanggal_jatuh_tempo_invoice'] ?? null,
                        'nilai_invoice' => $d['nilai_invoice'] ?? 0,
                        'nama_payor' => $d['nama_payor'] ?? null,
                        'jenis_payor' => $d['jenis_payor'] ?? null,
                        'no_perjanjian_pembayaran' => $d['no_perjanjian_pembayaran'] ?? null,
                        'tanggal_perjanjian_pembayaran' => $d['tanggal_perjanjian_pembayaran'] ?? null,
                        'penggunaan_kredit' => $d['penggunaan_kredit'] ?? null,
                        'plafond_kredit' => $d['plafond_kredit'] ?? 0,
                        'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
                        'jangka_waktu' => $d['jangka_waktu'] ?? null,
                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                        'jenis_agunan' => $d['jenis_agunan'] ?? null,
                        'nilai_agunan' => $d['nilai_agunan'] ?? 0,
                        'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                        'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
                        'ijp' => $d['ijp'] ?? 0,
                        'loan_number' => $loanNumber,
                        'catatan' => $d['catatan'] ?? null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        'no_sp_core_debitur' => $d['no_sp_core_debitur'] ?? null,
                        'institution_id' => $institutionMap[$nik] ?? null,
                        'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
                        'status_debitur' => $d['status_debitur'] ?? null,
                        'created_by' => $user->id,
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertDebitur($rows);

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $institutionInputs = data_get($request->all(), 'data.dataInstitution', []);
            $savedAttachments = [];

            foreach ($debiturFiles as $idx => $attachments) {
                $nik = data_get($institutionInputs, "{$idx}.institution_data.id_number") ?? 'UNKNOWN_ID';

                foreach ($attachments as $fileKey => $fileOrArray) {
                    if (is_array($fileOrArray)) {
                        foreach ($fileOrArray as $innerKey => $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $fn = "{$nik}-{$innerKey}-ajp";
                                $path = $file->storeAs('uploads/penjaminan/ajp', $fn . '.' . $ext, 's3');

                                $savedAttachments[] = [
                                    'trx_no' => $trxNo,
                                    'lampiran_id' => $innerKey,
                                    'file_name' => $fn,
                                    'status_doc' => 'N',
                                    'version' => 1,
                                    'mime_type' => $file->getMimeType(),
                                    'file_info' => $path,
                                    'created_at' => $nowJakarta,
                                ];
                            }
                        }
                    } elseif ($fileOrArray instanceof \Illuminate\Http\UploadedFile) {
                        $ext = $fileOrArray->getClientOriginalExtension();
                        $fn = "{$trxNo}-ajp-{$idx}-{$fileKey}";
                        $path = $fileOrArray->storeAs('uploads/penjaminan/ajp', $fn . '.' . $ext, 's3');

                        $savedAttachments[] = [
                            'trx_no' => $trxNo,
                            'lampiran_id' => $fileKey,
                            'file_name' => $fn,
                            'status_doc' => 'N',
                            'version' => 1,
                            'mime_type' => $fileOrArray->getMimeType(),
                            'file_info' => $path,
                            'created_at' => $nowJakarta,
                        ];
                    }
                }
            }

            $this->repository->insertLampiranDetails($savedAttachments);

            $this->repository->createPenjaminanFlow([
                'trx_no' => $trxNo,
                'trx_status' => 'NA',
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name,
                'updated_at' => null,
            ]);
        });

        return [
            'success' => true,
            'message' => $isDraft
                ? 'Data AJP berhasil diupdate sebagai draft'
                : 'Data AJP berhasil diupdate dan disubmit',
            'status' => 200,
        ];
    }

    public function getDetailPaymentAjp(Request $request): array
    {
        $key = base64_decode(config('services.secure.key'));
        $noSuratPermohonan = (string) $request->query('no_surat_permohonan', '');
        $trxNo = (string) $request->query('trx_no', '');
        $isSplit = (int) $request->query('is_split', 0);

        $dataHeader = $this->repository->getDetailPaymentPending($trxNo, $noSuratPermohonan, $isSplit);
        if ($dataHeader->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Data tidak ditemukan',
                'status' => 404,
            ];
        }

        $dataHeader->each(function ($row) use ($key) {
            $row->nik = AesHelper::decrypt($row->nik, $key);
        });

        $dataUnpaid = $this->repository->getDetailPaymentUnpaid($trxNo);

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

    public function getDetailListPaymentAjp(Request $request): array
    {
        $noSuratPermohonan = (string) $request->query('no_surat_permohonan', '');
        $trxNo = (string) $request->query('trx_no', '');
        $isSplit = (int) $request->query('is_split', 0);

        $dataHeader = $this->repository->getDetailListPaymentHeader($trxNo, $noSuratPermohonan, $isSplit);
        if (!$dataHeader) {
            return [
                'success' => false,
                'message' => 'Data tidak ditemukan',
                'status' => 404,
            ];
        }

        $dataDebitur = $this->repository->getDebiturForPaymentList((int) $dataHeader->id_multiguna_ajp);
        $debiturById = $dataDebitur->keyBy('id_trx_debitur');
        $debiturIds = $dataDebitur->pluck('id_trx_debitur')->filter()->unique()->values()->all();

        if (empty($debiturIds)) {
            return [
                'success' => true,
                'status' => 200,
                'data' => ['data' => []],
            ];
        }

        $schedules = $this->repository->getTenorSchedulesByDebiturIds($debiturIds);
        $schedulesUnpaid = $this->repository->getUnpaidSchedulesByDebiturIds($debiturIds);

        $result = $schedules
            ->groupBy('tenor_sequence')
            ->map(function ($rows, $tenor) use ($debiturById, $schedulesUnpaid) {
                $scheduleByDebitur = $rows->keyBy('id_trx_debitur');
                $unpaidSchedules = $schedulesUnpaid->where('tenor_sequence', $tenor);

                $listPending = $rows->where('status', 'Pending')->pluck('id_trx_debitur')->unique()->values()
                    ->map(function ($id) use ($debiturById, $scheduleByDebitur) {
                        $d = $debiturById->get($id);
                        if (!$d) {
                            return null;
                        }

                        $sch = $scheduleByDebitur->get($id);

                        return [
                            'id_trx_debitur' => $d->id_trx_debitur,
                            'no_sp_detail' => $d->no_sp_detail,
                            'loan_number' => $d->loan_number,
                            'nik' => $d->nik ?? null,
                            'invoice_number' => $sch->invoice_number,
                            'tanggal_realisasi' => $d->tanggal_realisasi,
                            'debitur_name' => $d->nama_nasabah,
                            'due_date' => $sch->due_date,
                            'status' => $sch->status,
                            'amount' => $sch?->amount,
                        ];
                    })
                    ->filter()
                    ->values();

                $listUnpaid = $unpaidSchedules->map(function ($unpaid) {
                    return [
                        'payment_id' => $unpaid->payment_id,
                        'order_payment_token' => $unpaid->order_payment_token,
                        'trx_no' => $unpaid->trx_no,
                        'order_id' => $unpaid->order_id,
                        'order_payment_url' => $unpaid->order_payment_url,
                        'total_debitur' => $unpaid->total_debitur,
                        'total_amount' => $unpaid->total_amount,
                    ];
                });

                return [
                    'tenor' => (int) $tenor,
                    'invoice_number' => '',
                    'debitur_list_pending' => $listPending,
                    'debitur_list_unpaid' => $listUnpaid,
                ];
            })
            ->values();

        return [
            'success' => true,
            'status' => 200,
            'data' => [
                'data' => $result,
            ],
        ];
    }

    public function uploadPembayaranManual(Request $request): array
    {
        validator($request->all(), [
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240',
        ])->validate();

        $decodedItems = json_decode((string) $request->selected_items);
        if (!is_array($decodedItems)) {
            return [
                'success' => false,
                'message' => 'Invalid selected item data.',
                'status' => 400,
            ];
        }

        $invoiceNumbers = collect($decodedItems)->pluck('invoice_number')->filter()->values()->all();
        $duplicateInvoiceNo = count($invoiceNumbers) !== count(array_unique($invoiceNumbers));
        if ($duplicateInvoiceNo) {
            return [
                'success' => false,
                'message' => 'Duplicate invoice data.',
                'status' => 404,
            ];
        }

        return DB::transaction(function () use ($request, $invoiceNumbers) {
            $tenorData = $this->repository->getTenorDataForManualUpload((string) $request->trx_no, $invoiceNumbers);
            $ajpHeader = $this->repository->getAjpHeaderByTrxNo((string) $request->trx_no);

            if ($tenorData->isEmpty() || !$ajpHeader) {
                return [
                    'success' => false,
                    'message' => 'Penjaminan AJP not found.',
                    'status' => 404,
                ];
            }

            $amountSum = (float) $tenorData->sum('amount');
            if ($amountSum != (float) $request->amount) {
                return [
                    'success' => false,
                    'message' => 'Incorrect amount.',
                    'status' => 400,
                ];
            }

            $idMultiguna = $tenorData->pluck('id_multiguna_ajp')->first();
            $tenorSequence = $tenorData->pluck('tenor_sequence')->first();
            $invoiceScope = count($tenorData) > 1 ? 'Merge Payment' : ((int) $tenorSequence === 0 ? 'Full Payment' : 'Split');

            $invoiceHeaderData = $this->repository->createAjpInvoiceHeader([
                'trx_no' => $request->trx_no,
                'debitur_trx_id' => $idMultiguna,
                'invoice_scope' => $invoiceScope,
                'total_amount' => $amountSum,
                'status' => 'Paid',
                'is_manual' => 1,
                'tenor_sequence' => $tenorSequence,
            ]);

            $newInvoiceId = (int) $invoiceHeaderData->invoice_id;
            $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

            $this->repository->createAjpPaymentGateway([
                'invoice_id' => $newInvoiceId,
                'status' => 'Paid',
                'payment_amount_ijp' => $amountSum,
                'order_id' => $orderId,
            ]);

            $scheduleIds = $tenorData->pluck('schedule_id')->all();
            $this->repository->updateSchedulesToPaid($scheduleIds, $newInvoiceId);

            $attachmentBuktiBayar = $request->file('file');
            $ext = $attachmentBuktiBayar->getClientOriginalExtension();
            $fn = $orderId . '-pembayaran-ajp';

            $localStackPath = $attachmentBuktiBayar->storeAs(
                'uploads/penjaminan/ajp',
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
                'file_info' => $localStackPath,
            ]);

            return [
                'success' => true,
                'message' => 'Bukti bayar manual successfully uploaded.',
                'status' => 200,
            ];
        });
    }

    private function getPenjaminanPKSData(object $user): array
    {
        $mitra = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('alias', 'is_syariah', 'is_conventional')
            ->first();

        if ($mitra == null) {
            return [
                'Success' => false,
                'Message' => 'Mitra not found for the authenticated user',
                'Data' => [],
            ];
        }

        $pksService = new CreatioService();
        $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
            'MitraID' => $mitra,
        ]);

        if ($response->status() !== 200) {
            return [
                'Success' => false,
                'Message' => 'Failed to get data from Core Creatio API with status: ' . $response->status(),
                'Data' => [],
            ];
        }

        $apiResBody = json_decode($response->body(), true);
        if (($apiResBody['Success'] ?? false) !== true) {
            return [
                'Success' => false,
                'Message' => 'Failed to get data from Core Creatio API with message: ' . ($apiResBody['Message'] ?? 'Unknown error'),
                'Data' => [],
            ];
        }

        if (!isset($apiResBody['Data']) || !is_array($apiResBody['Data'])) {
            $apiResBody['Data'] = [];
        }

        if ((bool) $mitra->is_syariah === true) {
            $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Syariah';
            }));
        } elseif ((bool) $mitra->is_conventional === true) {
            $apiResBody['Data'] = array_values(array_filter($apiResBody['Data'], function ($item) {
                return isset($item['JenisTransaksi']) && $item['JenisTransaksi'] === 'Non-Syariah';
            }));
        }

        return [
            'Success' => true,
            'Message' => 'PKS data retrieved successfully',
            'Data' => $apiResBody['Data'] ?? [],
        ];
    }

    public function validateDebiturBatchAjp(string $selectedPks, array $penjaminanPKSData, array $dataDebitur): array
    {
        $validateDataPks = $penjaminanPKSData['Data'] ?? [];
        $selectedPKS = null;

        foreach ($validateDataPks as $row) {
            if (($row['Name'] ?? null) === $selectedPks) {
                $selectedPKS = $row;
                break;
            }
        }

        if (!$selectedPKS) {
            return [
                'success' => false,
                'message' => "Nomor PKS {$selectedPks} tidak ditemukan",
                'list_debitur' => $dataDebitur,
                'dataDebitur' => [],
            ];
        }

        $riskPercentage = $selectedPKS['Macet'] ?? 0;
        $maxAmount = $selectedPKS['Maksimal'] ?? 0;
        $invalid = [];

        foreach ($dataDebitur as $i => &$rowDebitur) {
            unset($rowDebitur['debitur_ajp']['__raw']);

            $debitur = $rowDebitur['debitur_ajp'] ?? [];
            $debiturName = $debitur['nama_nasabah'] ?? null;
            $nik = $debitur['id_number'] ?? null;

            $tglJatuhTempo = data_get($rowDebitur, 'debitur_ajp.tanggal_jatuh_tempo');
            if (!empty($tglJatuhTempo)) {
                $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tglJatuhTempo)->startOfDay();
                if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_kredit' => $debitur['plafond_kredit'] ?? null,
                        'nilai_penjaminan' => $debitur['nilai_penjaminan'] ?? null,
                        'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini',
                    ];
                    continue;
                }
            }

            $tglRealisasi = data_get($rowDebitur, 'debitur_ajp.tanggal_realisasi');
            if (!empty($tglRealisasi)) {
                $realisasi = Carbon::createFromFormat('Y-m-d', (string) $tglRealisasi)->startOfDay();
                if (now()->startOfDay()->greaterThan($realisasi)) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_kredit' => $debitur['plafond_kredit'] ?? null,
                        'nilai_penjaminan' => $debitur['nilai_penjaminan'] ?? null,
                        'reason' => 'Tanggal Realisasi harus lebih dari hari ini',
                    ];
                    continue;
                }
            }

            $plafondKredit = (float) ($debitur['plafond_kredit'] ?? 0);
            $nilaiPenjaminan = $plafondKredit * ($riskPercentage / 100);

            $dataDebitur[$i]['debitur_ajp']['nilai_penjaminan'] = $nilaiPenjaminan;
            $dataDebitur[$i]['debitur_ajp']['jenis_penjaminan'] = $plafondKredit > $maxAmount ? 'CBC' : 'CAC';
            $dataDebitur[$i]['debitur_ajp']['status_debitur'] = $plafondKredit > $maxAmount ? 'Submitted' : 'Approved';
        }

        return [
            'success' => count($invalid) === 0,
            'message' => count($invalid) === 0
                ? 'Semua debitur valid'
                : 'Terdapat Data Debitur yang tidak sesuai',
            'list_debitur' => $invalid,
            'dataDebitur' => count($invalid) > 0 ? [] : $dataDebitur,
        ];
    }
}
