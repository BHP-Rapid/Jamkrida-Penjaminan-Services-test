<?php

namespace App\Http\Controllers;

use App\Helper\ApiResponse;
use App\Services\MultigunaService\MultigunaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MultigunaController extends Controller
{
    public function __construct(protected MultigunaService $multigunaService) {}

    public function store(Request $request)
    {
        $user = auth('sanctum')->user();
        $debugMsg = 'No tenant mitra data.';
        $mitraAlias = '';
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();
        if ($tenantMitraData) {
            $mitraAlias = $tenantMitraData->alias;
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Tenant mitra data not found.'
            ], 404);
        }
        try {
            $this->validate($request, [
                'data.noSuratPermohonan' => 'required|string',
                'data.pks' => 'required|string',
                'data.jenisProduk' => 'required|string',
                'data.bank' => 'required|string',
                'data.tglSuratPermohonan' => 'required|date',
                'data.spSplit' => 'required|string',
                'data.bankCabang' => 'nullable|string',
                'data.feeBasePercentage' => 'nullable|numeric',
                'data.teksPenjaminanSp' => 'nullable|string',
                'data.dataDebitur' => 'nullable|array',
                'data.dataDebitur.*.attachments' => 'nullable|array',
                'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);
            if ($request->data['trx_status'] == 'D') {
                if ($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Excel tidak boleh diisi Jika dalam Ingin Save as Draft'
                    ], 500);
                }
            }
            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return response()->json([
                    'status' => 'error',
                    'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
                ], 500);
            }

            if (empty($request->allFiles())) {
                return response()->json([
                    'success' => false,
                    'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                ], 422);
            }

            $selectedPks = $request->data['selectedPks'];
            $dataDebitur = $request->input('data.dataDebitur', []);
            // $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $result = ValidateDebitur::validateDebiturBatch([
                'selectedPks' => $selectedPks,
                'penjaminanPKSData' => $penjaminanPKSData,
                'dataDebitur' => $dataDebitur,
            ]);
            if (!$result['success']) {
                return response()->json($result, 422);
            }
            $dataDebitur = $result['dataDebitur'];
            DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias) {
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
                // if($flagPayment)
                // dd($request->data['spSplit'] );
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
                    'product' => 'mlt',
                    'mitra_id' => $mitraAlias,
                    'no_rek' => '012312'
                ]);

                $multiguna =  MultigunaTransaction::create([
                    'trx_no' => $trxNo,
                    // 'jenis_product' => $request->['jenisBond'],
                    'jenis_product_description' => 'Multiguna',
                    'pks_number' => $request->data['pks'],
                    'fee_base_number' => $request->data['feeBasePercentage'],
                    'fee_base_percentage' => $request->data['feeBasePercentage'],
                    'bank_name' => $request->data['bankCabang'],
                    'bank_code' => $request->data['bank'],
                    'text_certified' => $request->data['teksPenjaminanSp'],
                    'created_at' => $nowJakarta,
                ]);

                $multigunaId = $multiguna->getKey();
                $mitraId = $request->data['mitra_id'];
                $mitraAlias = TenantMitra::where('mitra_id', $mitraId)->value('alias') ?? $mitraId;
                $prefix = $mitraAlias . $currentYear;
                $lastLoan = MultigunaDebitur::lockForUpdate()
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
                // dd($request->data['dataInstitution']);
                $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
                    ->pluck('institution_data')
                    ->filter()
                    ->map(function ($value) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey, $mitraAlias) {
                        $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

                        $nik = $value['id_number'] ?? null;
                        $instId = (string) Str::uuid();
                        $nikHashed = hash_hmac('sha256', $nik, $hashKey);
                        if ($nik) {
                            $institutionMap[$nik] = $instId;
                        }
                        // dd($value);
                        return [
                            'category' => 'P',
                            'mitra_id' => $mitraAlias,
                            'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
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
                    })
                    ->values()
                    ->all();
                // dd($rowInstitutions);
                if (!empty($rowInstitutions)) {
                    Institution::insert($rowInstitutions);
                }
                $countDebitur = count($dataDebitur);
                // dd($countDebitur);
                $rows = collect($dataDebitur)
                    ->pluck('debitur_multiguna')
                    ->filter()
                    ->map(function (array $d, int $idx) use ($request, $multigunaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
                        $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
                        $spSequence = $idx + 1;
                        $baseSp = $request->data['noSuratPermohonan'];
                        $realisasi = Carbon::parse($d['tanggal_realisasi']);
                        $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
                        $jwBulan   = (int) ($d['jw_bulan'] ?? 0);
                        $tglAkhir = $realisasi->copy()->addMonthsNoOverflow($jwBulan);
                        $seq = $startSeq + $idx;
                        $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
                        $nik = $d['nik'] ?? null;
                        return [
                            'multiguna_trx_id' => $multigunaId,
                            'debitur_name' => $d['debitur_name'] ?? null,
                            'debitur_address' => $d['debitur_address'] ?? null,
                            // 'no_sp_detail' => ($request->data['spSplit'] == false) ? $baseSp  : $baseSp . '-' . $spSequence,
                            'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
                            'penggunaan_pembiayaan' => $d['penggunaan_pembiayaan'] ?? 0,
                            'ijk' => $d['ijk'] ?? null,
                            'nik' => $enc($d['nik']) ?? null,
                            'jenis_agunan' => $d['jenis_agunan'] ?? null,
                            'nilai_agunan' => $d['nilai_agunan'] ?? null,
                            'nilai_kafalah' => $d['nilai_kafalah'] ?? null,
                            'plafond_pembiayaan' => $d['plafond_pembiayaan_rp'] ?? 0,
                            'plafond_max_debitur' => $d['plafond_max_pembiayaan'] ?? 0,
                            'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
                            // 'tanggal_jatuh_tempo' => $tglAkhir->toDateString() ?? null,
                            'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
                            'jenis_makful_anhu' => $d['jenis_makful_anhu'] ?? null,
                            'jw_bulan' => $d['jw_bulan'] ?? null,
                            'loan_number' => $loanNumber,
                            'margin' => $d['marginbagi_hasilujrah_thn'] ?? 0,
                            'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
                            'institution_id' => $nik ? ($institutionMap[$nik] ?? null) : null,
                            'created_at' => $nowJakarta,
                            'status_debitur' => $d['status_debitur'],
                            'jenis_penjaminan' => $d['jenis_penjaminan']
                            // 'nilai_plafon_maksimal'=>$d[]
                        ];
                    })
                    ->values()
                    ->all();
                if (!empty($rows)) {
                    MultigunaDebitur::insert($rows);
                }

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

                    $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik")
                        ?? data_get($debiturInputs, "{$idx}.attachments.nik")
                        ?? 'UNKNOWN_NIK';

                    foreach ($attachments as $fileKey => $fileOrArray) {
                        if (is_array($fileOrArray)) {
                            foreach ($fileOrArray as $innerKey => $file) {
                                if ($file instanceof \Illuminate\Http\UploadedFile) {
                                    $ext = $file->getClientOriginalExtension();
                                    $unique = uniqid();
                                    $fn = "{$nik}-{$innerKey}-mlt";
                                    $path = $file->storeAs(
                                        'uploads/penjaminan/multiguna',
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
                                    'uploads/penjaminan/multiguna',
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
                if (!empty($savedAttachments)) {
                    DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                    // dd($savedAttachments);
                }
                if ($request->data['trx_status'] != 'D') {
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
            return response()->json([
                'success' => true,
                'message' => 'Data berhasil disubmit',
            ]);
        } catch (Exception $ex) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error While Insert Multiguna: ' . $ex->getMessage()
            ], 500);
        }
    }

    


    public function show($id)
    {
        if (empty($id)) {
            return ApiResponse::error('ID is required', 400);
        }

        try {
            $data = $this->multigunaService->getMultigunaDetailWithAttachments($id);

            return ApiResponse::success($data, 'Data retrieved successfully');
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Get Data Multiguna: ' . $ex->getMessage(), 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();
        try {
            $this->multigunaService->updateMultigunaDraft(
                $trxNo,
                $request->input(),
                $user->user_id ?? null,
                $user->name ?? null
            );

            return ApiResponse::success([], 'Data berhasil diupdate');
        } catch (ModelNotFoundException $ex) {
            return ApiResponse::error('Data tidak ditemukan', 404);
        } catch (Exception $ex) {
            return ApiResponse::error('Error While Updating Multiguna: ' . $ex->getMessage(), 500);
        }
    }
    

}
