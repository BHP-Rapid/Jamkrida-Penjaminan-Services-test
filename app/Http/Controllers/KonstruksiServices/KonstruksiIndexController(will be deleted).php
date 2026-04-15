<?php

namespace App\Http\Controllers\v2\Konstruksi;

use App\Exports\ExcelDataNormatifExport;
use App\Exports\ExcelDataNormatifKKPBJExport;
use App\Helpers\AesHelper;
use App\Http\Controllers\Controller;
use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturPaymentGateway;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Models\TrxInvoiceHeader;
use App\Models\v2\KonstruksiDebiturInvoiceHeader;
use App\Models\v2\KonstruksiDebiturTenorSchedule;
use App\Models\v2\MultigunaTrxKonstruksi;
use App\Models\v2\TrxDebiturKonstruksi;
use App\Services\CreatioService;
use App\Services\PenjaminanService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class KonstruksiIndexController extends Controller
{

    public function __construct()
    {
        //
    }

    public function ExportKonstruksi()
    {
        try {
            $filename = 'data_normatif_' . date('Y-m-d_H-i-s') . '.xlsx';
            return Excel::download(new ExcelDataNormatifKKPBJExport(), $filename);
        } catch (\Exception $e) {
            Log::error("", ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Error generating Excel file: ' . $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $user = auth('sanctum')->user();
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

            if (empty($request->allFiles()) && $request->data['trx_status'] != 'D') {
                return response()->json([
                    'success' => false,
                    'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                ], 422);
            }

            $selectedPks = $request->data['selectedPks'];
            $dataDebitur = $request->input('data.dataDebitur', []);

            $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return response()->json($result, 422);
            }


            DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias) {
                //
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
                    'product' => 'kkpbj',
                    'mitra_id' => $mitraAlias,
                    'no_rek' => '012312'
                ]);

                $konstruksi = MultigunaTrxKonstruksi::create([
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
                $lastLoan = TrxDebiturKonstruksi::lockForUpdate()
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

                if (!empty($rowInstitutions)) {
                    Institution::insert($rowInstitutions);
                }
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
                if (!empty($rows)) {
                    TrxDebiturKonstruksi::insert($rows);
                }
                $allFiles = $request->allFiles();
                $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
                $debiturInputs = $request->input('data.dataDebitur', []);
                $savedAttachments = [];
                // change point
                $docList = DB::table('setting_hdr as a')
                    ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
                    ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
                    ->select(DB::raw('UPPER(c.value) as value'), 'c.label', 'a.mitra_id', 'a.module', 'c.option2')
                    ->where('a.module', 'PENJAMINAN_SETTINGS')
                    ->where('b.product_id', 'kkpbj')
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
        } catch (Exception $e) {
            Log::error("", ["exception" => $e]);
            return response()->json([

                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        if (empty($id)) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required.'
            ], 400);
        }
        $trx_no = $id;
        try {
            //
            $penjaminanDetail = PenjaminanTransaction::join('multiguna_trx_kreditkonstruksi as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
                ->join('multiguna_trx_kreditkonstruksi', 'transaction_penjaminan_header.trx_no', '=', 'multiguna_trx_kreditkonstruksi.trx_no')
                ->where('transaction_penjaminan_header.trx_no', $trx_no)
                ->select('transaction_penjaminan_header.*', 'mt.*')
                ->first();

            if (!$penjaminanDetail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data not found.'
                ], 404);
            }

            $rows = DB::table('institution as a')
                ->join('trx_debitur_construction as b', 'a.institution_id', '=', 'b.institution_id')
                ->where('b.id_multiguna_konstruksi', $penjaminanDetail->id_multiguna_konstruksi)
                ->select('b.*', 'a.*')
                ->get();

            $lampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();

            if ($rows->isNotEmpty()) {
                $key = base64_decode(config('services.secure.key'));
                foreach ($rows as $row) {

                    if ($row->birth_date) {
                        $row->birth_date = AesHelper::decrypt($row->birth_date, $key);
                    }
                    if ($row->id_number) {
                        $row->id_number = AesHelper::decrypt($row->id_number, $key);
                    }
                    if ($row->email_1) {
                        $row->email_1 = AesHelper::decrypt($row->email_1, $key);
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
                                'presigned_url' => Storage::disk('s3')->temporaryUrl(
                                    $att->file_info,
                                    now()->addMinutes(15)
                                ),
                            ];
                            $row->attachments[] = $item;
                        }
                    }
                }
            }

            // flow multiguna
            $MultigunaFlow = PenjaminanFlow::where('trx_no', $trx_no)->orderBy('created_at', 'desc')->get();
            if ($MultigunaFlow != null) {
                $penjaminanDetail->flowMultiguna = $MultigunaFlow;
            }

            if ($rows != null) {
                $penjaminanDetail->debiturMultiguna = $rows;
            }

            return response()->json([
                'success' => true,
                'data' => $penjaminanDetail
            ], 200);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Get Data KKPBJ : ' . $ex->getMessage()
            ], 500);
        }
    }

    public function updateDraft(Request $request, $trxNo)
    {
        $user = auth('sanctum')->user();
        $debugMsg = 'No tenant mitra data.';
        $mitraAlias = '';
        $tenant_ID = '';
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
        if ($tenantMitraData) {
            $mitraAlias = $tenantMitraData->alias;
            $tenant_ID = $tenantMitraData->tenant_id;
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
                'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);

            $dataDebitur = $request->input('data.dataDebitur', []);
            $newStatus = $request->data['trx_status'];
            // Validate debitur data if submitting (not draft)
            if ($request->data['trx_status'] !== 'D' && !empty($dataDebitur)) {
                $penjaminanPKSResponse = $this->getPenjaminanPKS();
                $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
                if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
                    ], 500);
                }

                $selectedPks = $request->data['selectedPks'] ?? $request->data['pks'];
                $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
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
                $penjaminan = PenjaminanTransaction::lockForUpdate()
                    ->where('trx_no', $trxNo)
                    ->firstOrFail();

                // Update PenjaminanTransaction
                $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
                $spSplit = $request->boolean('data.spSplit');
                $penjaminan->update([
                    'no_surat_permohonan' => $request->data['noSuratPermohonan'],
                    'tanggal_surat_permohonan' => $permohonanDate,
                    'trx_status' => $request->data['trx_status'],
                    'status_sync_creatio' => 0,
                    'sp_split' => $spSplit,
                    'updated_at' => $nowJakarta,
                    'updated_by_id' => $user->user_id,
                    'updated_by_name' => $user->name,
                ]);


                $konstruksi = MultigunaTrxKonstruksi::lockForUpdate()
                    ->where('trx_no', $trxNo)
                    ->firstOrFail();


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
                    $lastLoan = TrxDebiturKonstruksi::lockForUpdate()
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
                    if (!empty($rows)) {
                        TrxDebiturKonstruksi::insert($rows);
                    }
                }
                $allFiles = $request->allFiles();
                $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
                $debiturInputs = $request->input('data.dataDebitur', []);

                if (!empty($debiturFiles)) {
                    DB::table('penjaminan_lampiran_dtl')->where('trx_no', $trxNo)->delete();

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

                    if (!empty($savedAttachments)) {
                        DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                    }
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

            return response()->json([
                'success' => true,
                'message' => 'Data berhasil diupdate',
            ]);
        } catch (Exception $ex) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error While Updating KKPBJ: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminan(Request $request)
    {
        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approvePenjaminanKonstruksi(
                $trx_no,
                auth('sanctum')->user()->user_id,
                auth('sanctum')->user()->name,
                "Perorangan"
            );
            return response()->json([
                'success' => true,
                'message' => 'Penjaminan Kredit Usaha successfully approved.'
            ]);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error while approving Penjaminan Kredit Usaha (' . $ex->getMessage() . ')'
            ], 500);
        }
    }

    public function GetDetailPaymentKonstruksi(Request $request)
    {
        $key = base64_decode(config('services.secure.key'));
        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $data = [];

            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('multiguna_trx_kreditkonstruksi as kut', 'tph.trx_no', '=', 'kut.trx_no')
                ->join('trx_debitur_construction as td', 'kut.id_multiguna_konstruksi', '=', 'td.id_multiguna_konstruksi')
                ->join('konstruksi_debitur_tenor_schedule as dts', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
                ->join('institution as i', 'i.institution_id', '=', 'td.institution_id')
                ->where('tph.trx_no', $trx_no)
                ->where('dts.status', 'Pending')
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'kut.id_multiguna_konstruksi',
                    'td.id_trx_debitur_konstruksi',
                    'td.nilai_kredit_per_proyek as plafond_kredit',
                    'i.id_number as nik',
                    'td.nama_proyek as nama_nasabah',
                    'dts.amount',
                    'dts.invoice_number',
                    'dts.due_date',
                    'dts.status'
                ])
                ->get();
            // dd($dataHeader);
            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }
            $dataHeader->each(function ($row) use ($key) {
                $decryptedNik = AesHelper::decrypt($row->nik, $key);

                $row->nik = $decryptedNik;
            });

            $dataUnpaid =
                KonstruksiDebiturInvoiceHeader::query()
                ->from('konstruksi_debitur_invoice_header as dih')
                // DB::table('konstruksi_debitur_invoice_header as dih')
                ->join('konstruksi_debitur_tenor_schedule as dts', 'dih.invoice_id', '=', 'dts.invoice_id')
                ->join('konstruksi_debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'dih.invoice_id')
                ->join('trx_debitur_construction as td', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
                ->where('dih.trx_no', $trx_no)
                ->where('dih.status', 'Unpaid')
                ->select(
                    'dpg.payment_id',
                    'dih.invoice_id',
                    'dpg.order_id',
                    'dpg.order_payment_url',
                    'dpg.order_payment_token',
                    'dts.tenor_sequence',
                    'dih.trx_no',
                    'dih.total_amount',
                    DB::raw('COUNT(td.id_trx_debitur_konstruksi) AS total_debitur')
                )
                ->groupBy(
                    'dpg.payment_id',
                    'dpg.order_id',
                    'dpg.order_payment_url',
                    'dts.tenor_sequence',
                    'dih.trx_no',
                    'dih.total_amount'
                )->get();

            $data = [
                'dataHeader' =>
                [
                    'data_pending' => $dataHeader,
                    'data_unpaid' => $dataUnpaid
                ]
            ];

            return response()->json($data);
        } catch (\Exception $ex) {
            Log::error("Error fetching payment details", [
                'exception' => $ex,
                'trx_no' => $trx_no ?? null,
                'no_surat_permohonan' => $no_surat_permohonan ?? null
            ]);

            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

    public function GetDetailListPaymentKonstruksi(Request $request)
    {
        try {
            $key = base64_decode(config('services.secure.key'));

            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('multiguna_trx_kreditkonstruksi as kut', 'tph.trx_no', '=', 'kut.trx_no')
                ->where('tph.trx_no', $trx_no)
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tph.*',
                    'kut.id_multiguna_konstruksi',
                ])
                ->first();

            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }

            $dataDebitur = TrxDebiturKonstruksi::where('id_multiguna_konstruksi', $dataHeader->id_multiguna_konstruksi)
                ->join('institution as i', 'i.institution_id', '=', 'trx_debitur_construction.institution_id')
                ->select(
                    'id_trx_debitur_konstruksi',
                    'no_sp_detail',
                    'loan_number',
                    'tanggal_realisasi',
                    'nama_proyek as nama_nasabah',
                    'i.id_number as nik',
                )
                ->orderBy('id_trx_debitur_konstruksi', 'asc')
                ->get();

            $debiturById = $dataDebitur->keyBy('id_trx_debitur_konstruksi');
            $debiturIds  = $dataDebitur->pluck('id_trx_debitur_konstruksi')->filter()->unique()->values();
            if ($debiturIds->isEmpty()) {
                return response()->json(['data' => []]);
            }

            $schedules = KonstruksiDebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
                ->WhereIn('status', ['Unpaid', 'Pending'])
                ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
                ->orderBy('tenor_sequence', 'asc')
                ->get();

            $schedulesUnpaid = KonstruksiDebiturInvoiceHeader::select(
                'dpg.payment_id',
                'dpg.order_id',
                'dpg.order_payment_url',
                'dpg.order_payment_token',
                'dts.tenor_sequence',
                'konstruksi_debitur_invoice_header.trx_no',
                'konstruksi_debitur_invoice_header.total_amount',
                DB::raw('COUNT(td.id_trx_debitur_konstruksi) as total_debitur')
            )
                ->join('konstruksi_debitur_tenor_schedule as dts', 'konstruksi_debitur_invoice_header.invoice_id', '=', 'dts.invoice_id')
                ->join('konstruksi_debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'konstruksi_debitur_invoice_header.invoice_id')
                ->join('trx_debitur_construction as td', 'td.id_trx_debitur_konstruksi', '=', 'dts.id_trx_debitur')
                // ->where('konstruksi_debitur_invoice_header.invoice_scope', '=', 'Merge Payment')
                ->where('dts.status', 'Unpaid')
                ->whereIn('dts.id_trx_debitur', $debiturIds)
                ->groupBy(
                    'dpg.order_id',
                    'dpg.order_payment_token',
                    'dpg.order_payment_url',
                    'dts.tenor_sequence',
                    'konstruksi_debitur_invoice_header.trx_no',
                    'konstruksi_debitur_invoice_header.total_amount'
                )
                ->get();

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

            return response()->json([
                'data' => $result
            ]);
        } catch (Exception $ex) {
            Log::error("Error fetching payment details", [
                'exception' => $ex,
                'trx_no' => $trx_no ?? null,
                'no_surat_permohonan' => $no_surat_permohonan ?? null
            ]);

            return response()->json(['message' => $ex->getMessage()], 500);
        }
    }

    public function uploadPembayaranManual(Request $request)
    {
        $this->validate($request, [
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            // 'selected_item_old' => 'required|array',
            // 'selected_item_old.*.amount' => 'required|numeric',
            // 'selected_item_old.*.invoice_number' => 'required|string|max:50',
            // 'selected_item_old.*.nik' => 'required|string|max:50'
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
        ]);

        if (
            !json_validate($request->selected_items) ||
            !is_array(json_decode($request->selected_items))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid selected item data.'
            ], 400);
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
            return response()->json([
                'success' => false,
                'message' => 'Duplicate invoice data.'
            ], 404);
        }

        DB::beginTransaction();
        try {
            $tenorData = DebiturTenorSchedule::query()
                ->from('multiguna_trx_kreditkonstruksi as kut')
                ->join('trx_debitur_construction as td', 'td.id_multiguna_konstruksi', '=', 'kut.id_multiguna_konstruksi')
                ->join('konstruksi_debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur_konstruksi')
                ->select([
                    'kut.id_kredit_usaha',
                    'dts.schedule_id',
                    'dts.id_trx_debitur',
                    'dts.tenor_sequence',
                    'dts.invoice_number',
                    'dts.amount',
                    'td.id_trx_debitur',
                    'td.no_sp_detail',
                ])
                ->whereIn('dts.invoice_number', $arrInvoiceNoTemp) //, ['INV-493', 'INV-474'])
                ->where('kut.trx_no', $request->trx_no)
                ->get();

            $mltHeader = PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->select('no_surat_permohonan')->first();
            if (count($tenorData) < 1 || !$mltHeader) {
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

            $noSuratPermohonan = $mltHeader->no_surat_permohonan;
            $idMultiguna = $tenorData->pluck('id_kredit_usaha_transaction')[0];
            $tenorSequence = $tenorData->pluck('tenor_sequence')[0];
            $invoiceScope = count($tenorData) > 1 ? 'Merge Payment' : ($tenorSequence == 0 ? 'Full Payment' : 'Split');
            $invoiceHeaderData = KonstruksiDebiturInvoiceHeader::create([
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
            DB::table('konstruksi_debitur_payment_gateway')->insert([
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
                // MultigunaTenorSchedule::where('schedule_id', $tenorDebitur->schedule_id)
                //     ->update([
                //         'invoice_id' => $newInvoiceId,
                //         'status' => 'Paid'
                //     ]);
            }

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
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error upload bukti bayar manual (' . $ex->getMessage() . ')'
            ], 500);
        }
    }


    public function getPenjaminanPKS()
    {
        $mitra_id = auth('sanctum')->user()->mitra_id;
        $mitra = TenantMitra::where('mitra_id', $mitra_id)
            ->select('alias')
            ->first();
        if ($mitra == null) {
            return response()->json([
                'success' => false,
                'message' => 'Mitra not found.'
            ], 404);
        }
        try {

            $pksService = new CreatioService();
            $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
                'MitraID' => $mitra->mitra_id
            ]);
            if ($response->status() !== 200) {
                throw new Exception("Failed to get data from Core Creatio API with status: " . $response->status());
            }
            $apiResBody = json_decode($response->body(), true);

            if ($apiResBody['Success'] !== true) {
                throw new Exception("Failed to get data from Core Creatio API with message: " . $apiResBody['Message']);
            }
            return response()->json($apiResBody);
        } catch (Exception $e) {
            Log::error("", ['exception' => $e]);
            return response()->json(['message' => $e->getMessage()], 500);
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

            // if (!in_array($debiturName, $terjaminNames, true) || !$nik || !isset($nikTerjaminSet[(string) $nik])) {
            //     $invalid[] = [
            //         'debitur_name' => $debiturName,
            //         'nik' => $nik,
            //         'plafond_pembiayaan_rp' => $rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'],
            //         // 'plafond_max_pembiayaan' => $rowDebitur['debitur_multiguna']['plafond_max_pembiayaan'],
            //         'nilai_kafalah' => $rowDebitur['debitur_multiguna']['nilai_kafalah'],
            //         // 'jenis_penjaminan' => $rowDebitur['debitur_multiguna']['jenis_penjaminan'],
            //         // 'status_debitur' => $rowDebitur['debitur_multiguna']['status_debitur'],
            //         'reason' => 'NIK and name does not registered on PKS'
            //     ];
            //     continue;
            // }
            $tgl = data_get($rowDebitur, 'debitur_multiguna.tanggal_jatuh_tempo');

            $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tgl)->startOfDay();
            if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_pembiayaan_rp' => $rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'] ?? null,
                    'nilai_kafalah' => $rowDebitur['debitur_multiguna']['nilai_kafalah'] ?? null,
                    'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini ',
                ];
                continue;
            }
            $tgl2 = data_get($rowDebitur, 'debitur_multiguna.tanggal_realisasi');
            $tglRealisasi = Carbon::createFromFormat('Y-m-d', (string) $tgl2)->startOfDay();
            if (now()->startOfDay()->greaterThan($tglRealisasi)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_pembiayaan_rp' => $rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'] ?? null,
                    'nilai_kafalah' => $rowDebitur['debitur_multiguna']['nilai_kafalah'] ?? null,
                    'reason' => 'Tanggal Realisasi harus lebih dari hari ini ',
                ];
                continue;
            }
            // Continue with other validation and logic
            $maks = ($nik && isset($maksPlafondByNik[(string) $nik]))
                ? $maksPlafondByNik[(string) $nik]
                : 0;

            // Update plafond max if needed
            if (isset($dataDebitur[$i]['debitur_multiguna'])) {
                $dataDebitur[$i]['debitur_multiguna']['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i]['debitur_multiguna'])) {
                    unset($dataDebitur[$i]['debitur_multiguna']['maksimal_nilai_plafond']);
                }
            } else {
                $dataDebitur[$i]['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i])) {
                    unset($dataDebitur[$i]['maksimal_nilai_plafond']);
                }
            }
            $nilaiKafalah = ($rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'] * ($riskPercentage / 100));
            $rowDebitur['debitur_multiguna']['nilai_kafalah'] = $nilaiKafalah;
            $rowDebitur['debitur_multiguna']['jenis_penjaminan'] = ($rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'] > $maxAmount) ? 'CBC' : 'CAC';
            $rowDebitur['debitur_multiguna']['status_debitur'] = ($rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'] > $maxAmount) ? 'Submitted' : 'Approved';
            $plafondPembiayaan = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp']);
            $plafondMax = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_multiguna']['plafond_max_pembiayaan']);
            if ($plafondPembiayaan > $plafondMax) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_pembiayaan_rp' => $rowDebitur['debitur_multiguna']['plafond_pembiayaan_rp'],
                    'plafond_max_pembiayaan' => $rowDebitur['debitur_multiguna']['plafond_max_pembiayaan'],
                    'nilai_kafalah' => $nilaiKafalah,
                    'jenis_penjaminan' => $rowDebitur['debitur_multiguna']['jenis_penjaminan'],
                    'status_debitur' => $rowDebitur['debitur_multiguna']['status_debitur'],
                    'reason' => 'Plafond Pembiayaan RP greater than Plafond Max Pembiayaan',
                ];
            }
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

    public function createTrxDebitur(Request $request)
    {
        try {
            $id = 15;
            DB::beginTransaction();

            $data = TrxDebiturKonstruksi::where('id_multiguna_konstruksi', $id)
                ->select('id_trx_debitur_konstruksi')->get();

            foreach ($data as $d) {
                // dd($d->id_trx_debitur_konstruksi);
                $lastInvoice = DB::table('konstruksi_debitur_tenor_schedule')->whereNotNull('invoice_number')
                    ->orderByDesc('schedule_id') // safer than created_at
                    ->lockForUpdate()
                    ->first();

                if ($lastInvoice) {
                    // Extract number from INV-008
                    $lastNumber = (int) substr($lastInvoice->invoice_number, 4);
                } else {
                    $lastNumber = 0;
                }

                $newNumber = $lastNumber + 1;

                $newInvoiceNumber = 'INV-' . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
                //sp_split true

                DB::table('konstruksi_debitur_tenor_schedule')->insert([
                    'id_trx_debitur' => $d->id_trx_debitur_konstruksi,
                    'tenor_sequence' => 1, //0 fullpayment; 1 installment
                    'due_date'       => now()->addMonth(), // >today()
                    'invoice_number' => $newInvoiceNumber, //nullabel?
                    // 'invoice_id'     => null, //null
                    'amount'         => 2000000, //terserah
                    'status'         => 'Pending',
                ]);
            }
            DB::commit();
            return response()->json(["message" => "success"]);
        } catch (Exception $ex) {
            DB::rollBack();
            throw $ex;
        }
    }
}
