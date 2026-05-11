<?php

namespace App\Services\MultigunaService;

use App\Exceptions\NotFoundException;
use App\Helpers\AesHelper;
use App\Helpers\ValidateDebitur;
use App\Repositories\MultigunaRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MultigunaService
{
    public function __construct(protected MultigunaRepository $repository) {}

    public function getMultigunaDetailWithAttachments(array $payload)
    {
        $trxNo = $payload['trx_no'] ?? null;

        $penjaminanDetail = $this->repository->getMultigunaDetail($trxNo);

        if (!$penjaminanDetail) {
            throw new Exception('Data not found.');
        }

        $rows = $this->repository->getMultigunaDebitur($penjaminanDetail->id_multiguna);
        $lampiran = $this->repository->getMultigunaLampiran($trxNo);

        if ($rows->isNotEmpty()) {
            $key = base64_decode(config('services.secure.key'));

            $debiturRows = $rows->map(function ($row) use ($key) {
                $item = $row->toArray();
                $item['nik'] = $row->nik
                    ? AesHelper::decrypt($row->nik, $key)
                    : null;
                $item['phone_1'] = $row->phone_1
                    ? AesHelper::decrypt($row->phone_1, $key)
                    : null;

                $item['email_1'] = $row->email_1
                    ? AesHelper::decrypt($row->email_1, $key)
                    : null;
                $item['attachments'] = [];

                return $item;
            })->values();

            // Attach lampiran to debitur
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

                foreach ($debiturRows as $index => $row) {
                    if (!empty($row['nik']) && $row['nik'] === $fileNik) {
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
                            'presigned_url' => Storage::disk('s3')->temporaryUrl(
                                $att->file_info,
                                now()->addMinutes(15)
                            ),
                        ];
                        $updatedRow = $debiturRows->get($index);
                        $updatedRow['attachments'][] = $item;
                        $debiturRows->put($index, $updatedRow);
                    }
                }
            }
        }

        // Add flow multiguna
        $multigunaFlow = $this->repository->getMultigunaFlow($trxNo);
        if ($multigunaFlow->isNotEmpty()) {
            $penjaminanDetail->flowMultiguna = $multigunaFlow;
        }

        if ($debiturRows->isNotEmpty()) {
            $penjaminanDetail->debiturMultiguna = $debiturRows;
        }

        return $penjaminanDetail;
    }

    public function updateMultigunaDraft(string $trxNo, array $data, ?int $userId, ?string $userName): void
    {
        DB::transaction(function () use ($trxNo, $data, $userId, $userName) {
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $penjaminan = $this->repository->findPenjaminanForUpdate($trxNo);
            $permohonanDate = $penjaminan->tanggal_surat_permohonan;
            if (!empty($data['tglSuratPermohonan'])) {
                $permohonanDate = Carbon::parse($data['tglSuratPermohonan'])->format('Y-m-d');
            }

            $this->repository->updatePenjaminanDraft($penjaminan, [
                'no_surat_permohonan' => $data['noSuratPermohonan'] ?? $penjaminan->no_surat_permohonan,
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $data['trx_status'] ?? $penjaminan->trx_status,
                'status_sync_creatio' => 0,
                'sp_split' => array_key_exists('spSplit', $data) ? ($data['spSplit'] ? 1 : 0) : $penjaminan->sp_split,
                'updated_at' => $nowJakarta,
                'updated_by_id' => $userId,
                'updated_by_name' => $userName,
            ]);

            $multiguna = $this->repository->findMultigunaForUpdate($trxNo);

            $this->repository->updateMultigunaDraft($trxNo, [
                'pks_number' => $data['pks'] ?? $multiguna->pks_number,
                'fee_base_number' => $data['tarifPercentage'] ?? $multiguna->fee_base_number,
                'fee_base_percentage' => $data['feeBasePercentage'] ?? $multiguna->fee_base_percentage,
                'bank_name' => $data['bank'] ?? $multiguna->bank_name,
                'bank_code' => $data['bankCabang'] ?? $multiguna->bank_code,
                'jenis_product_description' => $data['jenisProduk'] ?? $multiguna->jenis_product_description,
                'text_certified' => $data['teksPenjaminanSp'] ?? $multiguna->text_certified,
                'updated_at' => $nowJakarta,
            ]);

            // Keep this read to preserve old request contract until debitur update logic is moved.
            collect(data_get($data, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->values();
        });
    }

    public function storeMultiguna(array $payload, object $user, array $penjaminanPKSData)
    {
        if (empty($payload['files'])) {
            throw new NotFoundException('File upload wajib diisi (tidak ada file yang dikirim).');
        }

        $selectedPks = $payload['selectedPks'];
        $dataDebitur = $payload['dataDebitur'] ?? [];

        $result = ValidateDebitur::validateDebiturBatch([
            'selectedPks' => $selectedPks,
            'penjaminanPKSData' => $penjaminanPKSData,
            'dataDebitur' => $dataDebitur,
        ]);

        if (!$result['success']) {
            throw new NotFoundException('Validasi debitur gagal', $result['message'] ?? null, 422);
        }

        $dataDebitur = $result['dataDebitur'];

        DB::transaction(function () use ($payload, $user, $dataDebitur) {
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
            $permohonanDate = Carbon::parse($payload['tglSuratPermohonan'])->format('Y-m-d');
            $nowJakarta = $this->repository->getNowJakarta();
            $spSplit = $payload['spSplit'];

            $this->repository->createPenjaminanTransaction([
                'trx_no' => $trxNo,
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $payload['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $payload['trx_status'],
                'status_sync_creatio' => 0,
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'product' => 'mlt',
                'mitra_id' => $user->mitra_id,
                'no_rek' => '012312'
            ]);

            $multiguna = $this->repository->createMultigunaTransaction([
                'trx_no' => $trxNo,
                'jenis_product_description' => 'Multiguna',
                'pks_number' => $payload['pks'],
                'fee_base_number' => $payload['feeBasePercentage'],
                'fee_base_percentage' => $payload['feeBasePercentage'],
                'bank_name' => $payload['bankCabang'],
                'bank_code' => $payload['bank'],
                'text_certified' => $payload['teksPenjaminanSp'],
                'created_at' => $nowJakarta,
            ]);

            $multigunaId = $multiguna->getKey();
            $mitraId = $user->mitra_id;
            $currentMitraAlias = $this->repository->findMitraAlias($mitraId) ?? $mitraId;
            $prefix = $currentMitraAlias . $currentYear;
            $lastLoan = $this->repository->getLatestLoanNumberByPrefix($prefix);

            $startSeq = 1;
            if ($lastLoan) {
                $lastSeq = (int) substr($lastLoan, -4);
                $startSeq = $lastSeq + 1;
            }

            $institutionMap = [];
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');

            $rowInstitutions = collect(data_get($payload, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value) use ($nowJakarta, &$institutionMap, $user, $key, $hashKey, $currentMitraAlias) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();
                    $nikHashed = hash_hmac('sha256', $nik, $hashKey);

                    if ($nik) {
                        $institutionMap[$nik] = $instId;
                    }

                    return [
                        'category' => 'P',
                        'mitra_id' => $currentMitraAlias,
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
                        'id_number_hash' => $nikHashed,
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
                ->pluck('debitur_multiguna')
                ->filter()
                ->map(function (array $d, int $idx) use ($payload, $multigunaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $spSequence = $idx + 1;
                    $baseSp = $payload['noSuratPermohonan'];
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                    $nik = $d['nik'] ?? null;

                    return [
                        'multiguna_trx_id' => $multigunaId,
                        'debitur_name' => $d['debitur_name'] ?? null,
                        'debitur_address' => $d['debitur_address'] ?? null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        'penggunaan_pembiayaan' => $d['penggunaan_pembiayaan'] ?? 0,
                        'ijk' => $d['ijk'] ?? null,
                        'nik' => $enc($d['nik'] ?? null),
                        'jenis_agunan' => $d['jenis_agunan'] ?? null,
                        'nilai_agunan' => $d['nilai_agunan'] ?? null,
                        'nilai_kafalah' => $d['nilai_kafalah'] ?? null,
                        'plafond_pembiayaan' => $d['plafond_pembiayaan_rp'] ?? 0,
                        'plafond_max_debitur' => $d['plafond_max_pembiayaan'] ?? 0,
                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                        'jenis_makful_anhu' => $d['jenis_makful_anhu'] ?? null,
                        'jw_bulan' => $d['jw_bulan'] ?? null,
                        'loan_number' => $loanNumber,
                        'margin' => $d['marginbagi_hasilujrah_thn'] ?? 0,
                        'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                        'institution_id' => $nik ? ($institutionMap[$nik] ?? null) : null,
                        'created_at' => $nowJakarta,
                        'status_debitur' => $d['status_debitur'] ?? null,
                        'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
                    ];
                })
                ->values()
                ->all();

            $this->repository->insertMultigunaDebitur($rows);

            $debiturFiles = data_get('data.dataDebitur', []);
            $debiturInputs = $payload['data']['dataDebitur'] ?? [];
            $savedAttachments = [];

            foreach ($debiturFiles as $idx => $attachments) {
                $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik")
                    ?? data_get($debiturInputs, "{$idx}.attachments.nik")
                    ?? 'UNKNOWN_NIK';

                foreach ($attachments as $fileKey => $fileOrArray) {
                    if (is_array($fileOrArray)) {
                        foreach ($fileOrArray as $innerKey => $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $ext = $file->getClientOriginalExtension();
                                $fn = "{$nik}-{$innerKey}-mlt";
                                $path = $file->storeAs(
                                    'uploads/penjaminan/multiguna',
                                    $fn . '.' . $ext,
                                    's3'
                                );

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
                    } else {
                        $file = $fileOrArray;

                        if ($file instanceof UploadedFile) {
                            $ext = $file->getClientOriginalExtension();
                            $fn = "{$trxNo}-ktp-mlt-{$idx}-{$fileKey}";
                            $path = $file->storeAs(
                                'uploads/penjaminan/multiguna',
                                $fn . '.' . $ext,
                                's3'
                            );

                            $savedAttachments[] = [
                                'trx_no' => $trxNo,
                                'lampiran_id' => $fileKey,
                                'file_name' => $fn,
                                'status_doc' => 'N',
                                'version' => 1,
                                'mime_type' => $file->getMimeType(),
                                'file_info' => $path,
                                'created_at' => $nowJakarta,
                            ];
                        }
                    }
                }
            }

            $this->repository->insertLampiranDetails($savedAttachments);

            if ($payload['data']['trx_status'] != 'D') {
                $this->repository->createPenjaminanFlow([
                    'trx_no' => $trxNo,
                    'trx_status' => $payload['data']['trx_status'],
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null,
                ]);
            }
        });

        return [
            'message' => 'Data Product Multiguna berhasil disimpan',
        ];
    }



    public function processGetDetailListPaymentMLT(array $payload)
    {
        $key = base64_decode(config('services.secure.key'));
        $no_surat_permohonan = $payload['no_surat_permohonan'];
        $trx_no              = $payload['trx_no'];
        $isSplit             = $payload['is_split'];

        $dataHeader = $this->repository->getDetailHeaderPayment($trx_no, $no_surat_permohonan, $isSplit);
        if (!$dataHeader) {
            throw new NotFoundException('Data Payment tidak ditemukan');
        }
        $debiturData = $this->repository->getDetailListPaymentDebitur($dataHeader->id_multiguna);
        $debiturById = $debiturData->keyBy('id_trx_debitur');
        $debiturIds  = $debiturData->pluck('id_trx_debitur')->filter()->unique()->values();
        if ($debiturIds->isEmpty()) {
            throw new NotFoundException('Data debitur tidak ditemukan');
        }
        $debiturIds = $debiturIds->all();
        $schedules = $this->repository->getSchedules($debiturIds);
        $unpaid    = $this->repository->getUnpaidSchedules($debiturIds);
        $result = $this->mapResult($schedules, $debiturById, $unpaid);
        return  $result;
    }


    public function processGetDetailPaymentMLT(array $payload)
    {
        $key = base64_decode(config('services.secure.key'));
        $no_surat_permohonan = $payload['no_surat_permohonan'];
        $trx_no              = $payload['trx_no'];
        $isSplit             = $payload['is_split'];

        $dataHeader = $this->repository->getDetailListHeader($no_surat_permohonan, $trx_no, $isSplit);
        $dataHeader->each(function ($row) use ($key) {
            $decryptedNik = AesHelper::decrypt($row->nik, $key);
            $row->nik = $decryptedNik;
        });
        if (!$dataHeader) {
            throw new NotFoundException('Data Header tidak ditemukan');
        }
        $dataUnpaid = $this->repository->getDetailUnpaidPaymentMLT($dataHeader->id_multiguna);
        $result = [
            'dataHeader' =>
            [
                'data_pending' => $dataHeader,
                'data_unpaid' => $dataUnpaid
            ]
        ];
        return $result;
    }

    private function mapResult($schedules, $debiturById, $schedulesUnpaid)
    {
        return $schedules
            ->groupBy('tenor_sequence')
            ->map(function ($rows, $tenor) use ($debiturById, $schedulesUnpaid) {

                $scheduleByDebitur = $rows->keyBy('id_trx_debitur');
                $unpaidSchedules = $schedulesUnpaid->where('tenor_sequence', $tenor);

                $listPending = $rows->where('status', 'Pending')
                    ->pluck('id_trx_debitur')
                    ->unique()
                    ->map(function ($id) use ($debiturById, $scheduleByDebitur) {

                        $d = $debiturById->get($id);
                        if (!$d) return null;

                        $sch = $scheduleByDebitur->get($id);

                        return [
                            'id_trx_debitur' => $d->id_trx_debitur,
                            'no_sp_detail' => $d->no_sp_detail,
                            'loan_number' => $d->loan_number,
                            'nik' => $d->nik,
                            'invoice_number' => $sch->invoice_number,
                            'tanggal_realisasi' => $d->tanggal_realisasi,
                            'debitur_name' => $d->debitur_name,
                            'due_date' => $sch->due_date,
                            'status' => $sch->status,
                            'amount' => $sch?->amount,
                        ];
                    })
                    ->filter()
                    ->values();

                $listUnpaid = $unpaidSchedules->map(fn($u) => [
                    'payment_id' => $u->payment_id,
                    'order_payment_token' => $u->order_payment_token,
                    'trx_no' => $u->trx_no,
                    'order_id' => $u->order_id,
                    'order_payment_url' => $u->order_payment_url,
                    'total_debitur' => $u->total_debitur,
                    'total_amount' => $u->total_amount,
                ]);

                return [
                    'tenor' => (int) $tenor,
                    'invoice_number' => '',
                    'debitur_list_pending' => $listPending,
                    'debitur_list_unpaid' => $listUnpaid,
                ];
            })
            ->values();
    }
}
