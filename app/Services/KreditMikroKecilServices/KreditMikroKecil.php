<?php

namespace App\Services\KreditMikroKecilServices;

use App\Helpers\AesHelper;
use App\Models\AjpDebiturInvoiceHeader;
use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturPaymentGateway;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\MultigunaTrxKreditMikroKecil;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Repositories\KreditMikroKecilRepository;
use App\Services\CreatioService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KreditMikroKecil
{
    public function __construct(
        protected KreditMikroKecilRepository $repository
    ) {}
    public function processStore($request, $user)
    {
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();

        if (!$tenantMitraData) {
            throw new Exception('Tenant mitra data not found.', 404);
        }

        $mitraAlias = $tenantMitraData->alias;
        $tenant_ID = $tenantMitraData->tenant_id;

        if ($request->data['trx_status'] == 'D') {
            if (!empty($request->allFiles())) {
                throw new Exception('File upload tidak diperbolehkan saat Save as Draft.', 422);
            }
        } else {
            if (empty($request->allFiles())) {
                throw new Exception('File upload wajib diisi (tidak ada file yang dikirim).', 422);
            }
        }

        $penjaminanPKSResponse = $this->getPenjaminanPKS($user);
        $penjaminanPKSData = $penjaminanPKSResponse;

        if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
            throw new Exception($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data', 500);
        }

        if (empty($request->allFiles()) && $request->data['trx_status'] !== 'D') {
            throw new Exception('File upload wajib diisi (tidak ada file yang dikirim).', 422);
        }

        $selectedPks = $request->data['selectedPks'];
        $dataDebitur = $request->input('data.dataDebitur', []);

        $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);

        if (!$result['success']) {
            throw new Exception(json_encode($result), 422);
        }

        $dataDebitur = $result['dataDebitur'];

        DB::transaction(function () use ($request, $user, $dataDebitur, $mitraAlias, $tenant_ID) {

            $currentYear = date('Y');
            $currentMonth = date('m');

            $lastTrx = PenjaminanTransaction::lockForUpdate()
                ->where('trx_no', 'like', 'PNJ-' . $currentYear . '-' . $currentMonth . '%')
                ->orderBy('trx_no', 'desc')
                ->value('trx_no');

            if ($lastTrx) {
                $lastSequence = intval(substr($lastTrx, -4));
                $nextSeq = $lastSequence + 1;
            } else {
                $nextSeq = 1;
            }

            $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);

            $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $spSplit = $request->boolean('data.spSplit');

            PenjaminanTransaction::create([
                'trx_no' => $trxNo,
                'sp_split' => $spSplit,
                'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $request->data['trx_status'],
                'status_sync_creatio' => 0,
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'product' => 'kmk',
                'mitra_id' => $mitraAlias,
                'no_rek' => '012312'
            ]);

            $kmk = MultigunaTrxKreditMikroKecil::create([
                'trx_no' => $trxNo,
                'jenis_product' => 'kmk',
                'jenis_product_description' => 'Kredit Mikro Kecil',
                'pks_number' => $request->data['pks'],
                'fee_base_number' => $request->data['feeBasePercentage'],
                'fee_base_percentage' => $request->data['feeBasePercentage'],
                'bank_name' => $request->data['bankCabang'],
                'bank_code' => $request->data['bank'],
                'text_certified' => $request->data['teksPenjaminanSp'],
                'created_at' => $nowJakarta,
            ]);

            $kmkId = $kmk->getKey();

            $mitraId = $request->data['mitra_id'];
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

            $institutionMap = [];
            $key = base64_decode(config('services.secure.key'));
            $hashKey = config('services.secure.hash_key');

            $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                ->pluck('institution_data')
                ->filter()
                ->map(function ($value, $idx) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey, $mitraAlias, $tenant_ID) {
                    $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                    $nik = $value['id_number'] ?? null;
                    $instId = (string) Str::uuid();
                    $nikHashed = hash_hmac('sha256', $nik, $hashKey);

                    if ($nik) {
                        $institutionMap[$idx] = $instId;
                    }

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
                        'home_zipcode' => $value['home_zipcode'] ?? $value['kode_pos'] ?? null,
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

            $countDebitur = count($dataDebitur);

            $rows = collect($dataDebitur)
                ->pluck('debitur_multiguna')
                ->filter()
                ->map(function (array $d, int $idx) use ($request, $kmkId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {

                    $spSequence = $idx + 1;
                    $baseSp = $request->data['noSuratPermohonan'];
                    $jwBulan = (int) ($d['jangka_waktu'] ?? 0);
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

                    return [
                        'kredit_mikro_trx_id' => $kmkId,
                        'nama_nasabah' => $d['debitur_name'] ?? null,
                        'alamat_nasabah' => $d['debitur_address'] ?? null,
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                        'plafond_kredit' => $d['plafond_kredit'] ?? 0,
                        'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                        'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                        'loan_number' => $loanNumber,
                        'institution_id' => $institutionMap[$idx] ?? null,
                        'created_at' => $nowJakarta,
                        'status_debitur' => $d['status_debitur'],
                        'jenis_penjaminan' => $d['jenis_penjaminan']
                    ];
                })
                ->values()
                ->all();

            if (!empty($rows)) {
                TrxDebiturDefaultBase::insert($rows);
            }

            $allFiles = $request->allFiles();
            $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
            $debiturInputs = $request->input('data.dataDebitur', []);
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
                                $fn = "{$nik}-{$innerKey}-kmk";

                                $path = $file->storeAs(
                                    'uploads/penjaminan/kmk',
                                    $fn . "." . $ext,
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
                                    'created_at' => $nowJakarta
                                ];
                            }
                        }
                    } else {

                        if ($fileOrArray instanceof \Illuminate\Http\UploadedFile) {

                            $ext = $fileOrArray->getClientOriginalExtension();
                            $fn = "{$trxNo}-ktp-kmk-{$idx}-{$fileKey}";

                            $path = $fileOrArray->storeAs(
                                'uploads/penjaminan/kmk',
                                $fn . "." . $ext,
                                's3'
                            );

                            $savedAttachments[] = [
                                'trx_no' => $trxNo,
                                'lampiran_id' => $fileKey,
                                'file_name' => $fn,
                                'status_doc' => 'N',
                                'version' => 1,
                                'mime_type' => $fileOrArray->getMimeType(),
                                'file_info' => $path,
                                'created_at' => $nowJakarta
                            ];
                        }
                    }
                }
            }

            if (!empty($savedAttachments)) {
                DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
            }

            if ($request->data['trx_status'] != 'D') {
                PenjaminanFlow::create([
                    'trx_no' => $trxNo,
                    'trx_status' => $request->data['trx_status'],
                    'created_at' => $nowJakarta,
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                ]);
            }
        });

        return true;
    }

    public function getPenjaminanPKS($user)
    {
        $mitra_id = $user->mitra_id;

        $mitra = TenantMitra::where('mitra_id', $mitra_id)
            ->select('alias')
            ->first();

        if ($mitra == null) {
            throw new Exception('Mitra not found.', 404);
        }

        try {
            $pksService = new CreatioService();

            $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
                'MitraID' => $mitra->mitra_id
            ]);

            if ($response->status() !== 200) {
                throw new Exception("Failed API: " . $response->status());
            }

            $apiResBody = json_decode($response->body(), true);

            if ($apiResBody['Success'] !== true) {
                throw new Exception($apiResBody['Message']);
            }

            return $apiResBody;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public function validateDebiturBatch(string $selectedPks, array $penjaminanPKSData, array $dataDebitur): array
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
            ];
        }
        $riskPercentage = $selectedPKS['Macet'];
        $listTerjamin = $selectedPKS['ListTerjamin'] ?? [];
        $maksPlafondByNik = [];
        $nikTerjaminSet = [];

        foreach ($listTerjamin as $t) {
            $nikTerjamin = $t['NIK'];
            if (!$nikTerjamin) continue;
            $nikKey = (string) $nikTerjamin;
            $nikTerjaminSet[$nikKey] = true;
            $nilaiMaksimalPlafond = $t['MaksimalNilaiPlafond'] ?? 0;
            if (is_string($nilaiMaksimalPlafond)) {
                $nilaiMaksimalPlafond = preg_replace('/[^0-9\-]/', '', $nilaiMaksimalPlafond);
            }
            $maksPlafondByNik[$nikKey] = (int) $nilaiMaksimalPlafond;
        }

        $maxAmount = $selectedPKS['Maksimal'] ?? 0;
        $terjaminNames = array_column($listTerjamin, 'NamaTerjamin');
        $invalid = [];

        foreach ($dataDebitur as $i => &$rowDebitur) {
            unset($rowDebitur['debitur_multiguna']['__raw']);
            $debiturName = $rowDebitur['debitur_multiguna']['debitur_name'];
            $nik = $rowDebitur['debitur_multiguna']['nik'];
            $tgl = data_get($rowDebitur, 'debitur_multiguna.tanggal_jatuh_tempo');
            if (!$tgl || empty(trim((string) $tgl))) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Tanggal jatuh tempo tidak boleh kosong',
                ];
                continue;
            }

            try {
                $jatuhTempo = Carbon::createFromFormat('Y-m-d', trim((string) $tgl))->startOfDay();
            } catch (\Exception $e) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Format tanggal jatuh tempo tidak valid (harus Y-m-d)',
                ];
                continue;
            }

            if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini ',
                ];
                continue;
            }

            $tgl2 = data_get($rowDebitur, 'debitur_multiguna.tanggal_realisasi');

            if (!$tgl2 || empty(trim((string) $tgl2))) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Tanggal realisasi tidak boleh kosong',
                ];
                continue;
            }

            try {
                $tglRealisasi = Carbon::createFromFormat('Y-m-d', trim((string) $tgl2))->startOfDay();
            } catch (\Exception $e) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Format tanggal realisasi tidak valid (harus Y-m-d)',
                ];
                continue;
            }
            if (now()->startOfDay()->greaterThan($tglRealisasi)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_terjamin' => $rowDebitur['debitur_multiguna']['nilai_terjamin'] ?? null,
                    'reason' => 'Tanggal Realisasi harus lebih dari hari ini ',
                ];
                continue;
            }
            $nilaiPenjaminan = ($rowDebitur['debitur_multiguna']['plafond_kredit'] * ($riskPercentage / 100));
            $rowDebitur['debitur_multiguna']['nilai_penjaminan'] = $nilaiPenjaminan;
            $rowDebitur['debitur_multiguna']['jenis_penjaminan'] = ($rowDebitur['debitur_multiguna']['plafond_kredit'] > $maxAmount) ? 'CBC' : 'CAC';
            $rowDebitur['debitur_multiguna']['status_debitur'] = ($rowDebitur['debitur_multiguna']['plafond_kredit'] > $maxAmount) ? 'Submitted' : 'Approved';
            $plafondPembiayaan = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_multiguna']['plafond_kredit']);
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

    public function processUpdateDraft($request, $trxNo, $user)
    {
        $data = $request->input();

        DB::transaction(function () use ($data, $trxNo, $user) {

            $nowJakarta = Carbon::now('Asia/Jakarta');

            $key = base64_decode(config('services.secure.key'));

            $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

            $penjaminan = PenjaminanTransaction::lockForUpdate()
                ->where('trx_no', $trxNo)
                ->firstOrFail();

            $permohonanDate = Carbon::parse($data['tglSuratPermohonan'])->format('Y-m-d');

            $penjaminan->update([
                'no_surat_permohonan' => $data['noSuratPermohonan'],
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => $data['trx_status'] ?? $penjaminan->trx_status,
                'status_sync_creatio' => 0,
                'sp_split' => $data['spSplit'] == true ? 1 : 0,
                'updated_at' => $nowJakarta,
                'updated_by_id' => $user->user_id ?? null,
                'updated_by_name' => $user->name ?? null,
            ]);

            $multiguna = MultigunaTrxKreditMikroKecil::lockForUpdate()
                ->where('trx_no', $trxNo)
                ->firstOrFail();

            $multiguna->update([
                'pks_number' => $data['pks'] ?? $multiguna->pks_number,
                'fee_base_number' => $data['tarifPercentage'] ?? $multiguna->fee_base_number,
                'fee_base_percentage' => $data['feeBasePercentage'] ?? $multiguna->fee_base_percentage,
                'bank_name' => $data['bank'] ?? $multiguna->bank_name,
                'bank_code' => $data['bankCabang'] ?? $multiguna->bank_code,
                'jenis_product_description' => $data['jenisProduk'] ?? $multiguna->jenis_product_description,
                'text_certified' => $data['teksPenjaminanSp'],
                'updated_at' => $nowJakarta,
            ]);
        });

        return true;
    }

    public function processGetDetailPaymentKMK(array $payload)
    {
        $key = base64_decode(config('services.secure.key'));
        $no_surat_permohonan = $payload['no_surat_permohonan'];
        $trx_no              = $payload['trx_no'];
        $isSplit             = $payload['is_split'];

        $dataHeader = $this->repository->getPendingTenorSchedule($no_surat_permohonan, $trx_no, $isSplit);

        if (!$dataHeader) {
            throw new \Exception('Data tidak ditemukan', 404);
        }

        $dataHeader->each(function ($row) use ($key) {
            $decryptedNik = AesHelper::decrypt($row->nik, $key);
            $row->nik = $decryptedNik;
        });
        $dataUnpaid = $this->repository->getUnpaidData($trx_no);
        return [
            'dataHeader' => [
                'data_pending' => $dataHeader,
                'data_unpaid' => $dataUnpaid
            ]
        ];
    }

    public function processGetDetailListPaymentKMK($payload)
    {
        $key = base64_decode(config('services.secure.key'));

        try {
            $trxNo = $payload['trx_no'];
            $noSurat = $payload['no_surat_permohonan'];
            $isSplit = (int) $payload['is_split'];
            $header = $this->repository->getHeader($trxNo, $noSurat, $isSplit);
            if (!$header) {
                throw new \Exception('Data tidak ditemukan', 404);
            }

            $debitur = $this->repository->getDebiturByKreditId(
                $header->id_multiguna_kredit_mikro_kecil
            );

            $debitur->each(function ($row) use ($key) {
                $row->nik = AesHelper::decrypt($row->nik, $key);
            });

            $debiturById = $debitur->keyBy('id_trx_debitur');
            $ids = $debitur->pluck('id_trx_debitur')->filter()->unique();

            if ($ids->isEmpty()) {
                return ['data' => []];
            }

            $schedules = $this->repository->getSchedules($ids);
            $unpaid    = $this->repository->getUnpaidSchedules($ids);

            $result = $this->mapResult($schedules, $debiturById, $unpaid);

            return ['data' => $result];
        } catch (\Exception $ex) {
            Log::error("Error fetching payment details", [
                'exception' => $ex,
                'trx_no' => $trxNo ?? null,
            ]);

            throw $ex;
        }
    }
    public function processUploadPembayaranManualKMK($request)
    {
        if (!json_validate($request->selected_items) || !is_array(json_decode($request->selected_items))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid selected item data.'
            ], 400);
        }

        $parsedItem = json_decode($request->selected_items);

        $arrInvoiceNoTemp = collect($parsedItem)->pluck('invoice_number')->toArray();

        $duplicateInvoiceNo = count($arrInvoiceNoTemp) != count(array_unique($arrInvoiceNoTemp));

        if ($duplicateInvoiceNo) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate invoice data.'
            ], 404);
        }

        DB::beginTransaction();

        try {

            $tenorData = MultigunaTrxKreditMikroKecil::query()
                ->from('multiguna_trx_kredit_mikro_kecil as mtkmk')
                ->join('trx_debitur as td', 'td.kredit_mikro_trx_id', '=', 'mtkmk.id_multiguna_kredit_mikro_kecil')
                ->join('debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur')
                ->select([
                    'mtkmk.id_kredit_usaha',
                    'dts.schedule_id',
                    'dts.id_trx_debitur',
                    'dts.tenor_sequence',
                    'dts.invoice_number',
                    'dts.amount',
                    'td.id_trx_debitur',
                    'td.no_sp_detail',
                ])
                ->whereIn('dts.invoice_number', $arrInvoiceNoTemp)
                ->where('mtkmk.trx_no', $request->trx_no)
                ->get();

            $kmkHeader = PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->select('no_surat_permohonan')
                ->first();

            if (count($tenorData) < 1 || !$kmkHeader) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan multiguna not found.'
                ], 404);
            }

            $amountSum = $tenorData->sum('amount');

            if ($amountSum != $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect amount.'
                ], 400);
            }

            $noSuratPermohonan = $kmkHeader->no_surat_permohonan;

            $tenorSequence = $tenorData->pluck('tenor_sequence')[0];

            $invoiceScope = count($tenorData) > 1
                ? 'Merge Payment'
                : ($tenorSequence == 0 ? 'Full Payment' : 'Split');

            $invoiceHeaderData = DebiturInvoiceHeader::create([
                'trx_no' => $request->trx_no,
                'invoice_scope' => $invoiceScope,
                'total_amount' => $amountSum,
                'status' => 'Paid',
                'is_manual' => 1,
                'tenor_sequence' => $tenorSequence
            ]);

            $newInvoiceId = $invoiceHeaderData->invoice_id;

            $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

            DebiturPaymentGateway::create([
                'invoice_id' => $newInvoiceId,
                'status' => 'Paid',
                'payment_amount_ijp' => $amountSum,
                'order_id' => $orderId
            ]);

            foreach ($tenorData as $tenorDebitur) {

                DebiturTenorSchedule::where('schedule_id', $tenorDebitur->schedule_id)
                    ->update([
                        'invoice_id' => $newInvoiceId,
                        'status' => 'Paid'
                    ]);
            }

            $attachmentBuktiBayar = $request->file('file');

            $fileBase64 = base64_encode(file_get_contents($attachmentBuktiBayar->path()));

            $ext = $attachmentBuktiBayar->getClientOriginalExtension();

            $fn = $orderId . '-pembayaran-kmk';

            $debiturPayload = $tenorData->map(function ($itemDebitur) use ($noSuratPermohonan) {
                return [
                    'no_sp_detail' => $itemDebitur->no_sp_detail == null ? $noSuratPermohonan : $itemDebitur->no_sp_detail,
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

            $svcCreatio = new \App\Services\CreatioService();

            $response = $svcCreatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', $creatioPayload);

            if ($response->status() !== 200) {
                throw new \Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);

            if ($bodyResponse['Success'] !== true) {
                throw new \Exception("Failed to send Bukti Pembayaran Manual to Core Creatio API with message: " . $bodyResponse['Message']);
            }

            $localStackPath = $attachmentBuktiBayar->storeAs(
                'uploads/penjaminan/payment-kmk',
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

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bukti bayar manual successfully uploaded.'
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error upload bukti bayar manual (' . $e->getMessage() . ')'
            ], 500);
        }
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
                            'debitur_name' => $d->nama_nasabah,
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
