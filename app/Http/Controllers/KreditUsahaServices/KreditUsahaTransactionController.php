<?php

namespace App\Http\Controllers\v2;

use App\Helpers\AesHelper;
use App\Helpers\GetSnapStatusHelper;
use App\Http\Controllers\Controller;
use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturPaymentGateway;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\MappingValue;
use App\Models\Mitra;
use App\Models\MultigunaDebitur;
use App\Models\MultigunaInvoiceFullPayment;
use App\Models\MultigunaTenorSchedule;
use App\Models\NotifMitra;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanHdr;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Models\TrxInvoiceHeader;
use App\Models\TrxPaymentGateway;
use App\Models\v2\KreditUsahaTransaction;
use App\Services\CreatioService;
use App\Services\PenjaminanService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Midtrans\Config;

class KreditUsahaTransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
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
                'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);
            if ($request->data['trx_status'] == 'D') {
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
                        'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                    ], 422);
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

            $selectedPks = $request->data['selectedPks'];
            $dataDebitur = $request->input('data.dataDebitur', []);

            $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);

            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return response()->json($result, 422);
            }

            DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias, $tenant_ID) {
                $currentYear = date('Y');
                $currentMonth = date('m');
                $isDraft = $request->data['trx_status'] == 'D';
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

                $penjaminanTransaction = PenjaminanTransaction::create([
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

                $kreditUsaha =  KreditUsahaTransaction::create([
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
                $mitraId = $mitraAlias;
                $prefix = $mitraId . $currentYear;
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

                if (!$isDraft) {
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
                    if (!empty($rows)) {
                        TrxDebiturDefaultBase::insert($rows);
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
                    if (!empty($savedAttachments)) {
                        DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                        // dd($savedAttachments);
                    }
                    if ($request->data['trx_status'] != 'D') {
                        PenjaminanFlow::create([
                            'trx_no' => $trxNo,
                            'trx_status' => $request->data['trx_status'],
                            // 'created_at' => $nowJakarta,
                            'created_at' => now(),
                            'created_by_id' => $user->user_id,
                            'created_by_name' => $user->name,
                            'updated_at' => null
                        ]);
                    }
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
                'message' => 'Error While Insert Kredit Usaha: ' . $ex->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
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
            $penjaminanDetail = PenjaminanTransaction::join('kredit_usaha_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
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
                ->join('trx_debitur as b', 'a.institution_id', '=', 'b.institution_id')
                ->where('b.kredit_usaha_trx_id', $penjaminanDetail->id_kredit_usaha_transaction)
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
                'message' => 'Error While Get Data Kredit Usaha: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function ApprovePenjaminanKreditUsaha(Request $request)
    {

        $trx_no = $request->trxNo;
        // dd($trx_no);
        $method = $request->method();
        $fullUrl = $request->fullUrl();
        try {
            (new PenjaminanService())->approvePenjaminanKreditUsaha(
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

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    // public function updateDraft(Request $request, $trxNo)
    // {
    //     $user = auth('sanctum')->user();
    //     $debugMsg = 'No tenant mitra data.';
    //     $mitraAlias = '';
    //     $tenant_ID = '';
    //     $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
    //         ->select('mitra_id', 'alias', 'tenant_id')
    //         ->first();
    //     if ($tenantMitraData) {
    //         $mitraAlias = $tenantMitraData->alias;
    //         $tenant_ID = $tenantMitraData->tenant_id;
    //     } else {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Tenant mitra data not found.'
    //         ], 404);
    //     }

    //     try {
    //         $this->validate($request, [
    //             'data.noSuratPermohonan' => 'required|string',
    //             'data.pks' => 'required|string',
    //             'data.jenisProduk' => 'required|string',
    //             'data.bank' => 'required|string',
    //             'data.tglSuratPermohonan' => 'required|date',
    //             'data.spSplit' => 'required|string',
    //             'data.bankCabang' => 'nullable|string',
    //             'data.feeBasePercentage' => 'nullable|numeric',
    //             'data.teksPenjaminanSp' => 'nullable|string',
    //             'data.dataDebitur' => 'nullable|array',
    //             'data.dataDebitur.*.attachments' => 'nullable|array',
    //             'data.dataDebitur.*.attachments.nik' => 'nullable|string',
    //             'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
    //             'data.dataDebitur.*.attachments.uploads.*' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:2048',
    //             'data.dataInstitution' => 'nullable|array',
    //             'data.tariftarifPercentage' => 'nullable|numeric',
    //         ]);

    //         $dataDebitur = $request->input('data.dataDebitur', []);

    //         // Validate debitur data if submitting (not draft)
    //         if ($request->data['trx_status'] !== 'D' && !empty($dataDebitur)) {
    //             $penjaminanPKSResponse = $this->getPenjaminanPKS();
    //             $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
    //             if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
    //                 return response()->json([
    //                     'status' => 'error',
    //                     'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
    //                 ], 500);
    //             }

    //             $selectedPks = $request->data['selectedPks'] ?? $request->data['pks'];
    //             $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
    //             $dataDebitur = $result['dataDebitur'];
    //             if (!$result['success']) {
    //                 return response()->json($result, 422);
    //             }
    //         }

    //         DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias, $tenant_ID, $trxNo) {
    //             $nowJakarta = Carbon::now('Asia/Jakarta');
    //             $key = base64_decode(config('services.secure.key'));
    //             $hashKey = config('services.secure.hash_key');

    //             // Get dan lock transaksi utama
    //             $penjaminan = PenjaminanTransaction::lockForUpdate()
    //                 ->where('trx_no', $trxNo)
    //                 ->firstOrFail();

    //             // Update PenjaminanTransaction
    //             $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
    //             $spSplit = $request->boolean('data.spSplit');
    //             $penjaminan->update([
    //                 'no_surat_permohonan' => $request->data['noSuratPermohonan'],
    //                 'tanggal_surat_permohonan' => $permohonanDate,
    //                 'trx_status' => $request->data['trx_status'],
    //                 'status_sync_creatio' => 0,
    //                 'sp_split' => $spSplit,
    //                 'updated_at' => $nowJakarta,
    //                 'updated_by_id' => $user->user_id,
    //                 'updated_by_name' => $user->name,
    //             ]);

    //             // Get dan lock KreditUsahaTransaction
    //             $kreditUsaha = KreditUsahaTransaction::lockForUpdate()
    //                 ->where('trx_no', $trxNo)
    //                 ->firstOrFail();

    //             // Update KreditUsahaTransaction
    //             $kreditUsaha->update([
    //                 'pks_number' => $request->data['pks'],
    //                 'fee_base_number' => $request->data['feeBasePercentage'],
    //                 'fee_base_percentage' => $request->data['feeBasePercentage'],
    //                 'bank_name' => $request->data['bankCabang'],
    //                 'bank_code' => $request->data['bank'],
    //                 'text_certified' => $request->data['teksPenjaminanSp'],
    //                 'updated_at' => $nowJakarta,
    //             ]);

    //             $kreditUsahaId = $kreditUsaha->getKey();


    //             if (!empty($request->data['dataInstitution'])) {

    //                 $institutionMap = [];
    //                 $rowInstitutions = collect(data_get($request->data, 'dataInstitution', []))
    //                     ->pluck('institution_data')
    //                     ->filter()
    //                     ->map(function ($value, $idx) use ($mitraAlias, $nowJakarta, &$institutionMap, &$user, $key, $hashKey, $tenant_ID) {
    //                         $nik = $value['id_number'] ?? null;
    //                         $instId = (string) Str::uuid();
    //                         $nikHashed = hash_hmac('sha256', $nik, $hashKey);
    //                         $institutionMap[$idx] = $instId;
    //                         $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;

    //                         return [
    //                             'category' => 'P',
    //                             'mitra_id' => $mitraAlias,
    //                             'tenant_id' => $tenant_ID,
    //                             'id_issued_location' => '-',
    //                             'id_add_issued_location' => '-',
    //                             'id_add_type' => "-",
    //                             'created_by' => $user->user_id,
    //                             'full_name' => $value['full_name'] ?? null,
    //                             'home_province' => $value['home_province'] ?? null,
    //                             'home_city' => $value['home_city'] ?? 0,
    //                             'home_district' => $value['home_district'] ?? null,
    //                             'home_sub_district' => $value['home_sub_district'] ?? null,
    //                             'home_zipcode' => $value['home_zipcode'] ?? null,
    //                             'birth_place' => $value['birth_place'] ?? null,
    //                             'birth_date' => $enc($value['birth_date'] ?? null),
    //                             'gender' => $value['gender'] ?? null,
    //                             'id_type' => $value['id_type'] ?? null,
    //                             'id_number' => $enc($nik),
    //                             'id_number_hash' => $nikHashed,
    //                             'job_id' => $value['job_id'] ?? null,
    //                             'job_level' => $value['job_level'] ?? null,
    //                             'job_employer_name' => $value['job_employer_name'] ?? null,
    //                             'job_start_date' => $value['job_start_date'] ?? null,
    //                             'job_industry_type' => $value['job_industry_type'] ?? null,
    //                             'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
    //                             'phone_1' => $enc($value['phone_1'] ?? null),
    //                             'email_1' => $enc($value['email_1'] ?? null),
    //                             'tax_id' => $enc($value['npwp']),
    //                             'current_salary_currency' => $value['current_salary_currency'],
    //                             'tax_type' => 'npwp',
    //                             'institution_id' => $instId,
    //                             'created_at' => $nowJakarta,
    //                         ];
    //                     })
    //                     ->values()
    //                     ->all();

    //                 if (!empty($rowInstitutions)) {
    //                     Institution::insert($rowInstitutions);
    //                 }
    //             } else {
    //                 $institutionMap = [];
    //             }

    //             if (!empty($dataDebitur)) {
    //                 // Get loan number sequence
    //                 $currentYear = date('Y');
    //                 $mitraId = $mitraAlias;
    //                 $prefix = $mitraId . $currentYear;
    //                 $lastLoan = MultigunaDebitur::lockForUpdate()
    //                     ->where('loan_number', 'like', $prefix . '%')
    //                     ->orderBy('loan_number', 'desc')
    //                     ->value('loan_number');
    //                 $startSeq = 1;
    //                 if ($lastLoan) {
    //                     $lastSeq = (int) substr($lastLoan, -4);
    //                     $startSeq = $lastSeq + 1;
    //                 }

    //                 $countDebitur = count($dataDebitur);
    //                 $rows = collect($dataDebitur)
    //                     ->pluck('debitur_multiguna')
    //                     ->filter()
    //                     ->map(function (array $d, int $idx) use ($request, $kreditUsahaId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
    //                         $spSequence = $idx + 1;
    //                         $baseSp = $request->data['noSuratPermohonan'];
    //                         $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
    //                         $seq = $startSeq + $idx;
    //                         $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

    //                         return [
    //                             'kredit_usaha_trx_id' => $kreditUsahaId,
    //                             'nama_nasabah' => $d['debitur_name'] ?? null,
    //                             'alamat_nasabah' => $d['debitur_address'] ?? null,
    //                             'instansi' => $d['instansi'] ?? null,
    //                             'suku_bunga' => $d['suku_bunga'] ?? null,
    //                             'jenis_kredit' => $d['jenis_kredit'],
    //                             'sp3' => $d['sp3'] ?? null,
    //                             'npwp_principal' => $d['npwp_principal'] ?? null,
    //                             'limit_penarikan' => $d['limit_penarikan'] ?? null,
    //                             'penggunaan_kredit' => $d['penggunaan_kredit'] ?? 0,
    //                             'plafond_kredit' => $d['plafond_kredit'] ?? null,
    //                             'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
    //                             'tanggal_usia' => $d['tanggal_usia'] ?? null,
    //                             'jangka_waktu' => $d['jangka_waktu'] ?? null,
    //                             'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
    //                             'ijp' => $d['ijp'] ?? null,
    //                             'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
    //                             'tanggal_jatuh_tempo' => $jatuhTempo->toDateString() ?? null,
    //                             'jenis_agunan' => $d['jenis_agunan'] ?? null,
    //                             'nilai_agunan' => $d['nilai_agunan'] ?? null,
    //                             'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
    //                             'loan_number' => $loanNumber,
    //                             'base_plafond' => $d['plafond_kredit'] ?? null,
    //                             'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
    //                             'institution_id' => $institutionMap[$idx] ?? null,
    //                             'created_at' => $nowJakarta,
    //                         ];
    //                     })
    //                     ->values()
    //                     ->all();

    //                 if (!empty($rows)) {
    //                     TrxDebiturDefaultBase::insert($rows);
    //                 }
    //             }

    //             $allFiles = $request->allFiles();
    //             $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
    //             $debiturInputs = $request->input('data.dataDebitur', []);

    //             if (!empty($debiturFiles)) {
    //                 DB::table('penjaminan_lampiran_dtl')->where('trx_no', $trxNo)->delete();

    //                 $savedAttachments = [];
    //                 foreach ($debiturFiles as $idx => $attachments) {
    //                     $nik = data_get($debiturInputs, "{$idx}.debitur_multiguna.nik");

    //                     foreach ($attachments as $fileKey => $fileOrArray) {
    //                         if (is_array($fileOrArray)) {
    //                             foreach ($fileOrArray as $innerKey => $file) {
    //                                 if ($file instanceof \Illuminate\Http\UploadedFile) {
    //                                     $ext = $file->getClientOriginalExtension();
    //                                     $fn = "{$nik}-{$innerKey}-ku";
    //                                     $path = $file->storeAs(
    //                                         'uploads/penjaminan/kredit-usaha',
    //                                         $fn . "." . $ext,
    //                                         's3'
    //                                     );

    //                                     $savedAttachments[] = [
    //                                         'trx_no' => $trxNo,
    //                                         'lampiran_id' => $innerKey,
    //                                         'file_name' => $fn,
    //                                         'status_doc' => 'N',
    //                                         'version' => 1,
    //                                         'mime_type' => $file->getMimeType(),
    //                                         'file_info' => $path,
    //                                         'created_at' => $nowJakarta
    //                                     ];
    //                                 }
    //                             }
    //                         } else {
    //                             $file = $fileOrArray;
    //                             if ($file instanceof \Illuminate\Http\UploadedFile) {
    //                                 $ext = $file->getClientOriginalExtension();
    //                                 $fn = "{$trxNo}-ktp-ku-{$idx}-{$fileKey}";
    //                                 $path = $file->storeAs(
    //                                     'uploads/penjaminan/kredit-usaha',
    //                                     $fn . "." . $ext,
    //                                     's3'
    //                                 );

    //                                 $savedAttachments[] = [
    //                                     'trx_no' => $trxNo,
    //                                     'lampiran_id' => $fileKey,
    //                                     'file_name' => $fn,
    //                                     'status_doc' => 'N',
    //                                     'version' => 1,
    //                                     'mime_type' => $file->getMimeType(),
    //                                     'file_info' => $path,
    //                                     'created_at' => $nowJakarta
    //                                 ];
    //                             }
    //                         }
    //                     }
    //                 }

    //                 if (!empty($savedAttachments)) {
    //                     DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
    //                 }
    //             }

    //             if ($request->data['trx_status'] != 'D') {
    //                 PenjaminanFlow::where('trx_no', $trxNo)->delete();

    //                 PenjaminanFlow::create([
    //                     'trx_no' => $trxNo,
    //                     'trx_status' => $request->data['trx_status'],
    //                     'created_at' => $nowJakarta,
    //                     'created_by_id' => $user->user_id,
    //                     'created_by_name' => $user->name,
    //                     'updated_at' => null
    //                 ]);
    //             }
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Data berhasil diupdate',
    //         ]);
    //     } catch (Exception $ex) {
    //         DB::rollBack();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error While Updating Kredit Usaha: ' . $ex->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function MultigunaPayment(Request $request)
    {
        try {
            Config::$serverKey    = config('midtrans.server_key');
            Config::$isProduction = (bool) config('midtrans.is_production', false);
            Config::$isSanitized  = true;
            Config::$is3ds        = true;
            $nowJakarta = Carbon::now('Asia/Jakarta');
            // $input = [
            //     'trx_no' => $request->input('trx_no'),
            //     'noSuratPermohonan' => $request->input('noSuratPermohonan'),
            //     'tenorId' => $request->input('tenorId'),
            //     'product' => $request->input('product'),
            //     'debiturList' => $request->input('debiturList', [])
            // ];
            $trxNo = $request->input('trx_no');
            $noSuratPermohonan = $request->input('noSuratPermohonan');
            $debiturList = $request->input('debiturList', []);
            $idList = collect($debiturList)->pluck('IdDebitur')->filter()->values()->all();
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
                ->where('tph.trx_no', $trxNo)
                ->where('tph.no_surat_permohonan', $noSuratPermohonan)
                ->select([
                    'tph.*',
                    'mt.*',
                ])->first();
            // dd($dataHeader);
            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }
            $dataDebitur = MultigunaDebitur::where('multiguna_trx_id', $dataHeader->id_multiguna)->whereIn('id_trx_debitur', $idList)->get();
            if ($dataDebitur->isEmpty()) {
                return response()->json(['message' => 'Data Debitur tidak ditemukan'], 404);
            }
            $countDebitur = count($dataDebitur ?? []);
            DB::beginTransaction();
            if ($countDebitur > 1) {
                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
                $totalIjp = 0;
                $items = [];
                foreach ($dataDebitur as $debitur) {
                    // dd($debitur);
                    $getDataTenor = MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                        // ->where('tenor_sequence', $tenorId)
                        // ->where('status', 'Unpaid')
                        ->first();
                    $amount = (int) $getDataTenor->amount;
                    $totalIjp += $amount;

                    $items[] = [
                        'id'       => (string) $dataHeader->no_surat_permohonan . '-' . $debitur->id_trx_debitur,
                        'price'    => $amount,
                        'quantity' => 1,
                        'name'     => "PREMI IJP {$debitur->debitur_name} Full Payment PKS {$dataHeader->pks_number} ({$debitur->debitur_name})",
                    ];
                }


                if ($totalIjp > 0) {
                    $firstDebitur = $dataDebitur[0];
                    $customers = [
                        'first_name' => 'Multiple',
                        'last_name'  => 'Debitur',
                        'email'      => $firstDebitur->email ?? null,
                        'phone'      => $firstDebitur->phone ?? null,
                    ];

                    $params = [
                        'transaction_details' => [
                            'order_id'     => $orderId,
                            'gross_amount' => $totalIjp,
                        ],
                        'customer_details' => $customers,
                        'item_details'     => $items,
                    ];

                    $validateMultigunaPayment = TrxPaymentGateway::where('invoice_id', $getDataTenor->invoice_id)
                        ->orderBy('payment_id', 'desc')
                        ->first();
                    // dd($validateMultigunaPayment);
                    if ($validateMultigunaPayment === null) {
                        $invoiceHeader = TrxInvoiceHeader::create([
                            'trx_no' => $dataHeader->trx_no,
                            'multiguna_trx_id' => $dataHeader->id_multiguna,
                            'invoice_scope' => 'Merge Payment',
                            // 'id_trx_debitur' => null,
                            'total_amount' => $totalIjp,
                            'status' => 'Unpaid',
                            'created_at' => $nowJakarta,
                            'updated_at' => $nowJakarta,
                            'tenor_sequence' => 0,
                            'is_manual' => 0
                        ]);
                        $invoiceId = $invoiceHeader->invoice_id;
                        foreach ($dataDebitur as $debitur) {
                            MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                                // ->where('status', 'Pending')
                                ->update([
                                    'invoice_id' => $invoiceId,
                                    'updated_at' => $nowJakarta,
                                    'status' => 'Unpaid'
                                ]);
                        }

                        $created = $this->createNewPayment($params, $invoiceHeader, $orderId, $totalIjp, $nowJakarta);
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'created',
                            'order_id' => $created->original['order_id'],
                            'redirect_url' => $created->original['redirect_url'],
                            'token' => $created->original['token'],
                        ];
                    } else {
                        $checkExpired = $this->isPaymentExpired($validateMultigunaPayment);
                        if ($checkExpired === 'pending') {
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'pending',
                                'token' => $validateMultigunaPayment->order_payment_token,
                                'redirect_url' => $validateMultigunaPayment->order_payment_url,
                                'order_id' => $validateMultigunaPayment->order_id,
                            ];
                        } else {
                            $invoiceHeader = TrxInvoiceHeader::where('invoice_id', $validateMultigunaPayment->invoice_id)->first();
                            $updated = $this->updateNewPayment($params, $invoiceHeader, $orderId, $totalIjp, $nowJakarta);
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'updated',
                                'order_id' => $updated->original['order_id'],
                                'redirect_url' => $updated->original['redirect_url'],
                                'token' => $updated->original['token'],
                            ];
                        }
                    }
                }
            } else {
                foreach ($dataDebitur as $debitur) {
                    $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
                    $tenor = MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                        // ->where('status', 'Unpaid')
                        ->first();

                    if (!$tenor) {
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'error',
                            'message' => 'Tenor Unpaid tidak ditemukan'
                        ];
                        continue;
                    }
                    $ijp = (int) $tenor->amount;
                    $items = [
                        [
                            'id'       => (string) $dataHeader->no_surat_permohonan,
                            'price'    => $ijp,
                            'quantity' => 1,
                            'name'     => "PREMI IJP {$debitur->debitur_name} Tenor Ke - {$tenor->tenor_sequence} PKS {$dataHeader->pks_number}",
                        ]
                    ];

                    $nameParts = preg_split('/\s+/', trim($debitur->debitur_name), 2);
                    $customers = [
                        'first_name' => $nameParts[0] ?? $debitur->debitur_name,
                        'last_name'  => $nameParts[1] ?? '',
                        'email'      => $debitur->email ?? null,
                        'phone'      => $debitur->phone ?? null,
                    ];

                    $params = [
                        'transaction_details' => [
                            'order_id'     => $orderId,
                            'gross_amount' => $ijp,
                        ],
                        'customer_details' => $customers,
                        'item_details'     => $items,
                    ];

                    $validateMultigunaPayment = TrxPaymentGateway::where('invoice_id', $tenor->invoice_id)
                        ->orderBy('payment_id', 'desc')
                        ->first();

                    if ($validateMultigunaPayment === null) {
                        $invoiceHeader = TrxInvoiceHeader::create([
                            'trx_no' => $dataHeader->trx_no,
                            'multiguna_trx_id' => $dataHeader->id_multiguna,
                            'invoice_scope' => 'Merge Payment',
                            // 'id_trx_debitur' => null,
                            'total_amount' => $ijp,
                            'status' => 'Unpaid',
                            'created_at' => $nowJakarta,
                            'tenor_sequence' => 0,
                            'is_manual' => 0
                        ]);

                        $invoiceId = $invoiceHeader->invoice_id;

                        foreach ($dataDebitur as $debitur) {
                            MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                                // ->where('tenor_sequence', $tenorId)
                                // ->where('status', 'Unpaid')
                                ->update([
                                    'invoice_id' => $invoiceId,
                                    'updated_at' => $nowJakarta,
                                    'status' => 'Unpaid'
                                ]);
                        }

                        $created = $this->createNewPayment($params, $invoiceHeader, $orderId, $ijp, $nowJakarta);
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'created',
                            'order_id' => $created->original['order_id'],
                            'redirect_url' => $created->original['redirect_url'],
                            'token' => $created->original['token']
                        ];
                    } else {
                        $checkExpired = $this->isPaymentExpired($validateMultigunaPayment);
                        if ($checkExpired === 'pending') {
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'pending',
                                'token' => $validateMultigunaPayment->order_payment_token,
                                'redirect_url' => $validateMultigunaPayment->order_payment_url,
                                'order_id' => $validateMultigunaPayment->order_id,
                            ];
                        } else {
                            $invoiceHeader = TrxInvoiceHeader::where('invoice_id', $validateMultigunaPayment->invoice_id)->first();
                            $updated = $this->updateNewPayment($params, $invoiceHeader, $orderId, $ijp, $nowJakarta);
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'updated',
                                'order_id' => $created->original['order_id'],
                                'redirect_url' => $created->original['redirect_url'],
                                'token' => $created->original['token']
                            ];
                        }
                    }
                }
            }
            DB::commit();
            return response()->json(['results' => $results]);
        } catch (Exception $ex) {
            DB::rollBack();
            return response()->json(['message' => 'Error occurred while updating', 'error' => $ex->getMessage()], 500);
        }
    }
    private function isPaymentExpired($payment)
    {
        if (Carbon::now()->greaterThan(Carbon::parse($payment->expiry_date_time))) {
            return 'expired';
        } else {
            return 'pending';
        }
    }

    private function createNewPayment($params, $invoiceFP, $orderId, $ijp, $nowJakarta)
    {
        // dd($params);
        $snapToken = \Midtrans\Snap::getSnapToken($params);
        if (!$snapToken) {
            return response()->json(['success' => false, 'message' => 'Error Failed to generate url'], 500);
        }
        $combineToken = (config('midtrans.is_production') ?
            'https://app.midtrans.com/snap/v2/vtweb/' :
            'https://app.sandbox.midtrans.com/snap/v2/vtweb/') . $snapToken;

        // DD($combineToken);
        // DB::beginTransaction();
        TrxPaymentGateway::create([
            "invoice_id" => $invoiceFP->invoice_id,
            "status" => "Pending",
            "payment_amount_ijp" => $ijp,
            "order_id" => $orderId,
            "order_payment_token" => $snapToken,
            "order_payment_url" => $combineToken,
            'created_at' => $nowJakarta,
            'updated_at' => null
        ]);
        // DB::commit();

        return response()->json([
            'token'        => $snapToken,
            'redirect_url' => $combineToken,
            'order_id'     => $orderId,
        ]);
    }

    private function updateNewPayment($params, $invoiceFP, $orderId, $ijp, $nowJakarta)
    {
        // dd($invoiceFP);
        $snapToken = \Midtrans\Snap::getSnapToken($params);
        if (!$snapToken) {
            return response()->json(['success' => false, 'message' => 'Error Failed to generate url'], 500);
        }
        $combineToken = (config('midtrans.is_production') ?
            'https://app.midtrans.com/snap/v2/vtweb/' :
            'https://app.sandbox.midtrans.com/snap/v2/vtweb/') . $snapToken;
        DB::beginTransaction();
        $payment = TrxPaymentGateway::where('invoice_id', $invoiceFP->invoice_id)->first()->orderby('payment_id', 'desc');
        // dd($payment);
        $payment->update([
            "payment_amount_ijp" => $ijp,
            "order_id" => $orderId,
            "order_payment_token" => $snapToken,
            "order_payment_url" => $combineToken,
            "expiry_date_time" => null,
            "updated_at" => $nowJakarta
        ]);
        DB::commit();
        return response()->json([
            'token'        => $snapToken,
            'redirect_url' => $combineToken,
            'order_id'     => $orderId,
        ]);
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
                ->from('kredit_usaha_transaction as kut')
                ->join('trx_debitur as td', 'td.kredit_usaha_trx_id', '=', 'kut.id_kredit_usaha')
                ->join('debitur_tenor_schedule as dts', 'dts.id_trx_debitur', '=', 'td.id_trx_debitur')
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
                ->whereIn('dts.invoice_number', $arrInvoiceNoTemp)
                ->where('kut.trx_no', $request->trx_no)
                ->get();
            // dd($tenorData);
            $mltHeader = PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->select('no_surat_permohonan')->first();
            if (count($tenorData) < 1 || !$mltHeader) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan multiguna not found.'
                ], 404);
            }
            // dd($tenorData->sum('amount'));
            $amountSum = $tenorData->sum('amount');
            if ($amountSum != $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect amount.'
                ], 400);
            }

            // $collectTenorDebitur = collect($tenorData)->toArray();
            // dd($collectTenorDebitur);

            $noSuratPermohonan = $mltHeader->no_surat_permohonan;
            $idMultiguna = $tenorData->pluck('id_kredit_usaha_transaction')[0];
            $tenorSequence = $tenorData->pluck('tenor_sequence')[0];
            $invoiceScope = count($tenorData) > 1 ? 'Merge Payment' : ($tenorSequence == 0 ? 'Full Payment' : 'Split');
            $invoiceHeaderData = DebiturInvoiceHeader::create([
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
            DebiturPaymentGateway::create([
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
                DebiturTenorSchedule::where('schedule_id', $tenorDebitur->schedule_id)
                    ->update([
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
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error upload bukti bayar manual (' . $e->getMessage() . ')'
            ], 500);
        }
    }

    public function approvePenjaminannMultiguna($trx_no)
    {
        DB::beginTransaction();
        try {
            // Log::info('User ID for approval: ' . $user_id . ' Name: ' . $user_name);
            $penjaminan = PenjaminanTransaction::join('multiguna_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
                ->where('trx_no', $trx_no)
                ->where('status_sync_creatio', 0)
                ->first();

            if (!$penjaminan) {
                throw new Exception("Penjaminan {$trx_no} not found or already synced.");
            }

            $existingProduk = MappingValue::where('key', 'jns_prod')
                ->where('value', $penjaminan->jenis_prodproduct)
                ->select('option2')->first();

            $namaBankMitra = "";
            $multigunaTrx =
                // dd($existingProduk);
                $dataMitra = Mitra::where('mitra_id', $penjaminan->mitra_id)->first();
            $creatioPayload = [
                "PermohonanPenjaminanMultiguna" => [
                    [
                        "NIK" => $penjaminan->nama,
                        "PKS" => $penjaminan->jenis_nasabah,
                        "NilaiProyek" => $penjaminan->title,
                        "TanggalSuratPermohonan" => $penjaminan->nik,
                        "PeriodeAwalBerlaku" => $penjaminan->role,
                        "PeriodeAkhirBerlaku" => $penjaminan->tgl_lahir,
                        "TarifPercentage" => $penjaminan->alamat,
                        "IJP" => $penjaminan->jenis_kelamin,
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "BankCabang" => $penjaminan->jenis_kredit,
                        "FeeBasePercentage" => $penjaminan->plafon_kredit,
                        "TeksPercentagePenjaminandiSP" => $penjaminan->no_rek,
                        "LoanNumber" => $penjaminan->loan_number,
                        "NilaiAgunan" => $dataMitra->name_mitra,
                        "MitraId" => $penjaminan->nama,
                        "BookingNomorSP" => $existingProduk ? $existingProduk->option2 : $penjaminan->jenis_produk,
                        "JenisAgunan" => $penjaminan->jenis_bond,
                        "NamaNasabah" => $penjaminan->skema_penalty,
                        "JenisKelaminNasabah" => $penjaminan->jenis_persyaratan,
                        'updated_at' => now()
                    ]
                ]
            ];
            // dd($creatioPayload);

            $svcPenjCreatio = new CreatioService();
            $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/RegistrasiMultiguna', $creatioPayload);

            if ($response->status() !== 200) {
                throw new Exception("Failed to register Penjaminan Multiguna to Core Creatio API with status: " . $response->status());
            }

            $bodyResponse = json_decode($response->body(), true);

            if ($bodyResponse['Success'] !== true) {
                throw new Exception("Failed to register penjaminan to Core Creatio API with message: " . $bodyResponse['Message']);
            }
            //$binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();
            $binaryLampiran = PenjaminanLampiranDtl::where('trx_no', $trx_no)
                ->get();
            $filteredLampiran = $binaryLampiran->map(function ($item) {
                return [
                    'trx_no'     => $item->trx_no,
                    'lampiran_id' => $item->lampiran_id,
                    'file_name'  => $item->file_name,
                    'mime_type'  => $item->mime_type,
                ];
            });
            // dd($filteredLampiran);
            LOG::info("check response {$filteredLampiran}");
            $lampiranCodeList = $binaryLampiran->pluck('lampiran_id')->all();
            $namaJenisDokumenCreatio = MappingValue::where('key', 'lampiran')
                ->whereIn('value', $lampiranCodeList)
                ->select('value', 'option3')
                ->get();

            $listPerorangan = ['ktp', 'npywp'];
            $listSyaratUmum = ['app', 'npwp', 'ppp', 'siujk'];
            $listSyaratKhusus = ['nib', 'rdpj'];
            foreach ($binaryLampiran as $bin) {
                // $key = base64_decode(config('services.secure.key'));
                $fileName = $bin->file_name;
                $dataBase64 = $bin->data_base64;
                $lampiranId = $bin->lampiran_id;
                $mimeType = $bin->mime_type;
                $namaJenis = "";
                $jenisDokumenById = $namaJenisDokumenCreatio->firstWhere('value', strtolower($lampiranId));
                LOG::info("check jenis dokumen {$jenisDokumenById}");
                if ($jenisDokumenById) {
                    $namaJenis = $jenisDokumenById->option3;
                }
                // check if dokumen is in perorangan
                if (in_array(strtolower($bin->lampiran_id), $listPerorangan)) {
                    $mime = $mimeType ?: 'application/octet-stream';
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Perorangan",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                if (in_array(strtolower($bin->lampiran_id), $listSyaratUmum)) {
                    $mime = $mimeType ?: 'application/octet-stream';
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Umum",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }

                // check if dokumen is in syarat khusus
                if (in_array(strtolower($bin->lampiran_id), $listSyaratKhusus)) {
                    $finalBase64 = $dataBase64;
                    $payloadDocument = [
                        "NomorPermohonan" => $penjaminan->no_surat_permohonan,
                        "NamaDokumen" =>  $fileName,
                        "JenisDokumen" => $namaJenis,
                        "TipeDokumen" => "Syarat Khusus",
                        "DataBase64" => $finalBase64
                    ];
                    $response = $svcPenjCreatio->request('post', '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan', $payloadDocument);
                    LOG::info("check inside looping {$response} {$namaJenis} {$fileName}");
                    if ($response->status() !== 200) {
                        throw new Exception("Failed to Send Document penjaminan " . $namaJenis . " to Core Creatio API with status: " . $response->status());
                    }
                    $bodyResponse = json_decode($response->body(), true);
                    if ($bodyResponse['Success'] !== true) {
                        throw new Exception("Failed to Send penjaminan " . $namaJenis . " to Core Creatio API with message: " . $bodyResponse['Message']);
                    }
                }
            }

            // $iterateUpload = 0;
            // foreach ($binaryLampiran as $bin) {
            //     $iterateUpload++;
            //     $subUrl = '/0/rest/PermohonanPenjaminan/AttachDokumenPermohonan';
            //     $paramTrx = '?NomorPermohonan=' . $trxNo;
            //     $paramJenisDokumen = '&JenisDokumen=Profil Perusahaan Principal';
            //     $paramTipeDokumen = '&TipeDokumen=Syarat Umum';
            //     $paramNamaDokumen = '&NamaDokumen=' . $bin['name'];
            //     $urlWithParams = $subUrl . $paramTrx . $paramJenisDokumen . $paramTipeDokumen . $paramNamaDokumen;
            //     $uploadRes = $svcPenjCreatio->request('binary', $urlWithParams, [], [], 1, $bin['data'], $bin['type']);

            //     if ($uploadRes->status() !== 200) {
            //         throw new Exception("Failed to upload document " . $iterateUpload . " to Core Creatio API with status: " . $uploadRes->status());
            //     }

            //     $uploadResBody = json_decode($uploadRes->body(), true);

            //     if ($uploadResBody['Success'] !== true) {
            //         throw new Exception("Failed to upload document " . $iterateUpload . " to Core Creatio API with message: " . $uploadResBody['Message']);
            //     }
            // }

            $notifCreatioPayload = [
                "Title" => "Mitra Portal Notification",
                "Subject" => "Register Penjaminan Success",
                // "Contact" => $request->nama
                "Contact" => "Supervisor"
            ];

            $notifRes = $svcPenjCreatio->request('post', '/0/rest/Notification/SendNotification', $notifCreatioPayload);

            // if ($notifRes->status() !== 200) {
            //     throw new Exception("Failed to send notification to Core Creatio API with status: " . $notifRes->status());
            // }

            $notifResBody = json_decode($notifRes->body(), true);

            // if ($notifResBody['Success'] !== true) {
            //     throw new Exception("Failed to send notification to Core Creatio API with message: " . $notifResBody['Message']);
            // }


            // Kirim SMS setelah berhasil simpan
            $noTelp = $penjaminan->no_telp;

            // Format nomor telepon ke internasional (Indonesia)
            if (str_starts_with($noTelp, '0')) {
                $noTelp = '62' . substr($noTelp, 1); // contoh: 08123... → 628123...
            }
            LOG::info("reaching final update for penjaminan {$trx_no} with phone number {$noTelp}");
            // Final update
            PenjaminanHdr::where('trx_no', $trx_no)->update([
                'status_sync_creatio' => 1,
                'trx_status' => $penjaminan->plafon_kredit >= $penjaminan->nilai_proyek ? 'WFP' : 'S',
                'updated_by_id' => auth('sanctum')->user()->user_id,
                'updated_by_name' => auth('sanctum')->user()->name,
                'updated_at' => now(),
            ]);

            PenjaminanFlow::insert([
                'trx_no' => $trx_no,
                'trx_status' => 'S',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by_id' => auth('sanctum')->user()->user_id,
                'created_by_name' => auth('sanctum')->user()->name,
                'status' => true
            ]);

            NotifMitra::create([
                'mitra_user_id' => auth('sanctum')->user()->user_id,
                'title' => "Mitra Portal - Klaim",
                'message' => "Status claim dengan nomor " . $trx_no . " menjadi " . "Submitted",
            ]);

            if ($penjaminan->plafon_kredit >= $penjaminan->nilai_proyek) {
                PenjaminanFlow::insert([
                    'trx_no' => $trx_no,
                    'trx_status' => 'WFP',
                    'created_at' => now(),
                    'updated_at' => now(),
                    'created_by_id' => auth('sanctum')->user()->user_id,
                    'created_by_name' => auth('sanctum')->user()->name
                ]);

                NotifMitra::create([
                    'mitra_user_id' => auth('sanctum')->user()->user_id,
                    'title' => "Mitra Portal - Klaim",
                    'message' => "Status claim dengan nomor " . $trx_no . " menjadi " . "Waiting For Payment",
                ]);
            }

            DB::commit();
            Log::info("Penjaminan {$trx_no} approved successfully.");
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error approving penjaminan {$trx_no}: {$e->getMessage()}");
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
                'list_debitur' => [],
                'dataDebitur' => [],
            ];
        }

        $riskPercentage = $selectedPKS['Macet'] ?? 0;
        $listTerjamin = $selectedPKS['ListTerjamin'] ?? [];
        $maxAmount = $selectedPKS['Maksimal'] ?? 0;

        if (empty($listTerjamin)) {
            return [
                'success' => false,
                'message' => "Data terjamin tidak tersedia untuk PKS {$selectedPks}",
                'list_debitur' => [],
                'dataDebitur' => [],
            ];
        }

        $maksPlafondByNik = [];
        $nikTerjaminSet = [];

        foreach ($listTerjamin as $t) {
            $nikTerjamin = $t['NIK'] ?? null;
            if (!$nikTerjamin) continue;
            $nikKey = (string) $nikTerjamin;
            $nikTerjaminSet[$nikKey] = true;
            $nilaiMaksimalPlafond = $t['MaksimalNilaiPlafond'] ?? 0;
            if (is_string($nilaiMaksimalPlafond)) {
                $nilaiMaksimalPlafond = preg_replace('/[^0-9\-]/', '', $nilaiMaksimalPlafond);
            }
            $maksPlafondByNik[$nikKey] = (int) $nilaiMaksimalPlafond;
        }

        $terjaminNames = array_column($listTerjamin, 'NamaTerjamin');
        $invalid = [];

        foreach ($dataDebitur as $i => $rowDebitur) {
            if (!isset($rowDebitur['debitur_multiguna']) || !is_array($rowDebitur['debitur_multiguna'])) {
                $invalid[] = [
                    'debitur_name' => 'Unknown',
                    'nik' => 'Unknown',
                    'reason' => 'Invalid debitur data structure'
                ];
                continue;
            }

            $debiturName = $rowDebitur['debitur_multiguna']['debitur_name'] ?? null;
            $nik = $rowDebitur['debitur_multiguna']['nik'] ?? null;

            // if (!$debiturName || !$nik) {
            //     $invalid[] = [

            //         'debitur_name' => $debiturName,
            //         'nik' => $nik,
            //         'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
            //         'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
            //         'reason' => 'Nama debitur dan NIK harus diisi'
            //     ];
            //     continue;
            // }

            // if (!in_array($debiturName, $terjaminNames, true) || !isset($nikTerjaminSet[(string) $nik])) {
            //     $invalid[] = [
            //         'debitur_name' => $debiturName,
            //         'nik' => $nik,
            //         'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
            //         'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
            //         'reason' => 'NIK and name does not registered on PKS'
            //     ];
            //     continue;
            // }

            $tgl = data_get($rowDebitur, 'debitur_multiguna.tanggal_jatuh_tempo');

            if (!$tgl) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal jatuh tempo harus diisi',
                ];
                continue;
            }

            try {
                $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tgl)->startOfDay();
                if (now()->startOfDay()->greaterThanOrEqualTo($jatuhTempo)) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                        'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                        'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini',
                    ];
                    continue;
                }
            } catch (Exception $e) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                    'reason' => 'Format tanggal jatuh tempo tidak valid (gunakan format Y-m-d)',
                ];
                continue;
            }

            $tgl2 = data_get($rowDebitur, 'debitur_multiguna.tanggal_realisasi');
            if (!$tgl2) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal realisasi harus diisi',
                ];
                continue;
            }

            try {
                $tglRealisasi = Carbon::createFromFormat('Y-m-d', (string) $tgl2)->startOfDay();
                if (now()->startOfDay()->greaterThan($tglRealisasi)) {
                    $invalid[] = [
                        'debitur_name' => $debiturName,
                        'nik' => $nik,
                        'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                        'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                        'reason' => 'Tanggal realisasi harus lebih dari atau sama dengan hari ini',
                    ];
                    continue;
                }
            } catch (Exception $e) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                    'reason' => 'Format tanggal realisasi tidak valid (gunakan format Y-m-d)',
                ];
                continue;
            }

            $plafondKredit = $rowDebitur['debitur_multiguna']['plafond_kredit'] ?? 0;
            if (!$plafondKredit || $plafondKredit <= 0) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $plafondKredit,
                    'nilai_penjaminan' => $rowDebitur['debitur_multiguna']['nilai_penjaminan'] ?? null,
                    'reason' => 'Plafond kredit harus diisi dan lebih dari 0',
                ];
                continue;
            }

            $maks = ($nik && isset($maksPlafondByNik[(string) $nik]))
                ? $maksPlafondByNik[(string) $nik]
                : 0;

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

            $nilaiKafalah = ($rowDebitur['debitur_multiguna']['plafond_kredit'] * ($riskPercentage / 100));
            $rowDebitur['debitur_multiguna']['nilai_penjaminan'] = $nilaiKafalah;
            $rowDebitur['debitur_multiguna']['jenis_kredit'] = ($rowDebitur['debitur_multiguna']['plafond_kredit'] > $maxAmount) ? 'CBC' : 'CAC';
            $rowDebitur['debitur_multiguna']['status_debitur'] = ($rowDebitur['debitur_multiguna']['plafond_kredit'] > $maxAmount) ? 'Submitted' : 'Approved';

            $plafondPembiayaan = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_multiguna']['plafond_kredit']);
            $plafondMax = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_multiguna']['plafond_max_pembiayaan'] ?? 0);
            if ($plafondPembiayaan > $plafondMax && $plafondMax > 0) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_multiguna']['plafond_kredit'],
                    'plafond_max_pembiayaan' => $rowDebitur['debitur_multiguna']['plafond_max_pembiayaan'],
                    'nilai_penjaminan' => $nilaiKafalah,
                    'jenis_kredit' => $rowDebitur['debitur_multiguna']['jenis_kredit'],
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



    public function MultigunaPaymentSplit(Request $request)
    {
        try {
            Config::$serverKey    = config('midtrans.server_key');
            Config::$isProduction = (bool) config('midtrans.is_production', false);
            Config::$isSanitized  = true;
            Config::$is3ds        = true;
            // $input = [
            //     'trx_no' => $request->input('trx_no'),
            //     'noSuratPermohonan' => $request->input('noSuratPermohonan'),
            //     'tenorId' => $request->input('tenorId'),
            //     'product' => $request->input('product'),
            //     'debiturList' => $request->input('debiturList', [])
            // ];
            // dd($input);

            $nowJakarta = Carbon::now('Asia/Jakarta');
            $trxNo = $request->input('trx_no');
            $noSuratPermohonan = $request->input('noSuratPermohonan');
            $tenorId = $request->input('tenorId');
            $debiturList = $request->input('debiturList', []);
            $idList = collect($debiturList)->pluck('IdDebitur')->filter()->values()->all();
            // $invoiceNumber = $request->input('invoice_number');
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
                ->where('tph.trx_no', $trxNo)
                ->where('tph.no_surat_permohonan', $noSuratPermohonan)
                ->select([
                    'tph.*',
                    'mt.*',
                ])->first();
            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }

            $dataDebitur = MultigunaDebitur::where('multiguna_trx_id', $dataHeader->id_multiguna)->whereIn('id_trx_debitur', $idList)->get();

            if ($dataDebitur->isEmpty()) {
                return response()->json(['message' => 'Data Debitur tidak ditemukan'], 404);
            }
            $results = [];

            $countDebitur = count($dataDebitur ?? []);
            if ($countDebitur > 1) {

                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);
                $totalIjp = 0;
                $items = [];

                foreach ($dataDebitur as $debitur) {
                    $tenor = MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                        ->where('tenor_sequence', $tenorId)
                        // ->where('status', 'Unpaid')
                        ->first();

                    if (!$tenor) {
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'error',
                            'message' => 'Tenor Unpaid tidak ditemukan'
                        ];
                        continue;
                    }

                    $amount = (int) $tenor->amount;
                    $totalIjp += $amount;

                    $items[] = [
                        'id'       => (string) $dataHeader->no_surat_permohonan . '-' . $debitur->id_trx_debitur,
                        'price'    => $amount,
                        'quantity' => 1,
                        'name'     => "PREMI IJP {$debitur->debitur_name} Tenor Ke - {$tenor->tenor_sequence} PKS {$dataHeader->pks_number} ({$debitur->debitur_name})",
                    ];
                }


                if ($totalIjp > 0) {
                    $firstDebitur = $dataDebitur[0];
                    $customers = [
                        'first_name' => 'Multiple',
                        'last_name'  => 'Debitur',
                        'email'      => $firstDebitur->email ?? null,
                        'phone'      => $firstDebitur->phone ?? null,
                    ];

                    $params = [
                        'transaction_details' => [
                            'order_id'     => $orderId,
                            'gross_amount' => $totalIjp,
                        ],
                        'customer_details' => $customers,
                        'item_details'     => $items,
                    ];

                    $validateMultigunaPayment = TrxPaymentGateway::where('invoice_id', $tenor->invoice_id)
                        ->orderBy('payment_id', 'desc')
                        ->first();

                    if ($validateMultigunaPayment === null) {
                        $invoiceHeader = TrxInvoiceHeader::create([
                            'trx_no' => $dataHeader->trx_no,
                            'multiguna_trx_id' => $dataHeader->id_multiguna,
                            'invoice_scope' => 'Merge Payment',
                            // 'id_trx_debitur' => null,
                            'total_amount' => $totalIjp,
                            'status' => 'Unpaid',
                            'created_at' => $nowJakarta,
                            'updated_at' => $nowJakarta,
                            'tenor_sequence' => $tenorId,
                        ]);

                        $invoiceId = $invoiceHeader->invoice_id;

                        foreach ($dataDebitur as $debitur) {
                            MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                                ->where('tenor_sequence', $tenorId)
                                // ->where('status', 'Unpaid')
                                ->update([
                                    'invoice_id' => $invoiceId,
                                    'updated_at' => $nowJakarta,
                                    'status' => 'Unpaid'
                                ]);
                        }

                        $created = $this->createNewPayment($params, $invoiceHeader, $orderId, $totalIjp, $nowJakarta);
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'created',
                            'order_id' => $created->original['order_id'],
                            'redirect_url' => $created->original['redirect_url'],
                            'token' => $created->original['token'],
                        ];
                    } else {
                        $checkExpired = $this->isPaymentExpired($validateMultigunaPayment);
                        if ($checkExpired === 'pending') {
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'pending',
                                'token' => $validateMultigunaPayment->order_payment_token,
                                'redirect_url' => $validateMultigunaPayment->order_payment_url,
                                'order_id' => $validateMultigunaPayment->order_id,
                            ];
                        } else {
                            $invoiceHeader = TrxInvoiceHeader::where('invoice_id', $validateMultigunaPayment->invoice_id)->first();
                            $updated = $this->updateNewPayment($params, $invoiceHeader, $orderId, $totalIjp, $nowJakarta);
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'updated',
                                'order_id' => $updated->original['order_id'],
                                'redirect_url' => $updated->original['redirect_url'],
                                'token' => $updated->original['token'],
                            ];
                        }
                    }
                }
            } else {
                foreach ($dataDebitur as $debitur) {
                    $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

                    $tenor = MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                        ->where('tenor_sequence', $tenorId)
                        // ->where('status', 'Unpaid')
                        ->first();

                    if (!$tenor) {
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'error',
                            'message' => 'Tenor Unpaid tidak ditemukan'
                        ];
                        continue;
                    }

                    $ijp = (int) $tenor->amount;

                    $items = [
                        [
                            'id'       => (string) $dataHeader->no_surat_permohonan,
                            'price'    => $ijp,
                            'quantity' => 1,
                            'name'     => "PREMI IJP {$debitur->debitur_name} Tenor Ke - {$tenor->tenor_sequence} PKS {$dataHeader->pks_number}",
                        ]
                    ];

                    $nameParts = preg_split('/\s+/', trim($debitur->debitur_name), 2);
                    $customers = [
                        'first_name' => $nameParts[0] ?? $debitur->debitur_name,
                        'last_name'  => $nameParts[1] ?? '',
                        'email'      => $debitur->email ?? null,
                        'phone'      => $debitur->phone ?? null,
                    ];

                    $params = [
                        'transaction_details' => [
                            'order_id'     => $orderId,
                            'gross_amount' => $ijp,
                        ],
                        'customer_details' => $customers,
                        'item_details'     => $items,
                    ];

                    $validateMultigunaPayment = TrxPaymentGateway::where('invoice_id', $tenor->invoice_id)
                        ->orderBy('payment_id', 'desc')
                        ->first();

                    if ($validateMultigunaPayment === null) {
                        $invoiceHeader = TrxInvoiceHeader::create([
                            'trx_no' => $dataHeader->trx_no,
                            'multiguna_trx_id' => $dataHeader->id_multiguna,
                            'invoice_scope' => 'Merge Payment',
                            // 'id_trx_debitur' => null,
                            'total_amount' => $ijp,
                            'status' => 'Unpaid',
                            'created_at' => $nowJakarta,
                            'updated_at' => $nowJakarta,
                            'tenor_sequence' => $tenorId,
                        ]);

                        $invoiceId = $invoiceHeader->invoice_id;

                        foreach ($dataDebitur as $debitur) {
                            MultigunaTenorSchedule::where('id_trx_debitur', $debitur->id_trx_debitur)
                                ->where('tenor_sequence', $tenorId)
                                // ->where('status', 'Unpaid')
                                ->update([
                                    'invoice_id' => $invoiceId,
                                    'updated_at' => $nowJakarta,
                                    'status' => 'Unpaid'
                                ]);
                        }

                        $created = $this->createNewPayment($params, $invoiceHeader, $orderId, $ijp, $nowJakarta);
                        $results[] = [
                            'debitur_id' => $debitur->id_trx_debitur,
                            'status' => 'created',
                            'order_id' => $created->original['order_id'],
                            'redirect_url' => $created->original['redirect_url'],
                            'token' => $created->original['token'],
                        ];
                    } else {
                        $checkExpired = $this->isPaymentExpired($validateMultigunaPayment);
                        if ($checkExpired === 'pending') {
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'pending',
                                'token' => $validateMultigunaPayment->order_payment_token,
                                'redirect_url' => $validateMultigunaPayment->order_payment_url,
                                'order_id' => $validateMultigunaPayment->order_id,
                            ];
                        } else {
                            $invoiceHeader = TrxInvoiceHeader::where('invoice_id', $validateMultigunaPayment->invoice_id)->first();
                            $updated = $this->updateNewPayment($params, $invoiceHeader, $orderId, $ijp, $nowJakarta);
                            $results[] = [
                                'debitur_id' => $debitur->id_trx_debitur,
                                'status' => 'updated',
                                'order_id' => $updated->original['order_id'],
                                'redirect_url' => $updated->original['redirect_url'],
                                'token' => $updated->original['token'],
                            ];
                        }
                    }
                }
            }

            return response()->json(['results' => $results]);
        } catch (Exception $ex) {
            DB::rollBack();
            return response()->json(['message' => 'Error occurred while updating', 'error' => $ex->getMessage()], 500);
        }
    }

    public function GetDetailPaymentKreditUsaha(Request $request)
    {
        $key = base64_decode(config('services.secure.key'));

        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $data = [];
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('kredit_usaha_transaction as kut', 'tph.trx_no', '=', 'kut.trx_no')
                ->join('trx_debitur as td', 'kut.id_kredit_usaha_transaction', '=', 'td.kredit_usaha_trx_id')
                ->join('debitur_tenor_schedule as dts', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
                ->where('tph.trx_no', $trx_no)
                ->where('dts.status', 'Pending')
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'kut.id_kredit_usaha_transaction',
                    'td.id_trx_debitur',
                    'td.plafond_kredit',
                    // 'td.nik',
                    'td.nama_nasabah',
                    'dts.amount',
                    'dts.invoice_number',
                    'dts.due_date',
                    'dts.status'
                ])
                ->get();
            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }
            $dataHeader->each(function ($row) use ($key) {
                $decryptedNik = AesHelper::decrypt($row->nik, $key);

                $row->nik = $decryptedNik;
            });

            $dataUnpaid = DebiturInvoiceHeader::query()
                ->from('debitur_invoice_header as dih')
                ->join('debitur_tenor_schedule as dts', 'dih.invoice_id', '=', 'dts.invoice_id')
                ->join('debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'dih.invoice_id')
                ->join('trx_debitur as td', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
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
                    DB::raw('COUNT(td.id_trx_debitur) AS total_debitur')
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

    public function GetDetailListPaymentKreditUsaha(Request $request)
    {
        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('kredit_usaha_transaction as kut', 'tph.trx_no', '=', 'kut.trx_no')
                ->where('tph.trx_no', $trx_no)
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tph.*',
                    'kut.id_kredit_usaha_transaction',
                ])
                ->first();

            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }

            $dataDebitur = TrxDebiturDefaultBase::where('kredit_usaha_trx_id', $dataHeader->id_kredit_usaha_transaction)
                ->select(
                    'id_trx_debitur',
                    'no_sp_detail',
                    'loan_number',
                    'tanggal_realisasi',
                    'nama_nasabah'
                )
                ->orderBy('id_trx_debitur', 'asc')
                ->get();

            $debiturById = $dataDebitur->keyBy('id_trx_debitur');
            $debiturIds  = $dataDebitur->pluck('id_trx_debitur')->filter()->unique()->values();
            if ($debiturIds->isEmpty()) {
                return response()->json(['data' => []]);
            }
            $schedules = DebiturTenorSchedule::whereIn('id_trx_debitur', $debiturIds)
                ->WhereIn('status', ['Unpaid', 'Pending'])
                ->select('id_trx_debitur', 'tenor_sequence', 'amount', 'due_date', 'status', 'invoice_number')
                ->orderBy('tenor_sequence', 'asc')
                ->get();

            $schedulesUnpaid = DebiturInvoiceHeader::select(
                'dpg.payment_id',
                'dpg.order_id',
                'dpg.order_payment_url',
                'dpg.order_payment_token',
                'dts.tenor_sequence',
                'debitur_invoice_header.trx_no',
                'debitur_invoice_header.total_amount',
                DB::raw('COUNT(td.id_trx_debitur) as total_debitur')
            )
                ->join('debitur_tenor_schedule as dts', 'debitur_invoice_header.invoice_id', '=', 'dts.invoice_id')
                ->join('debitur_payment_gateway as dpg', 'dpg.invoice_id', '=', 'debitur_invoice_header.invoice_id')
                ->join('trx_debitur as td', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
                // ->where('debitur_invoice_header.invoice_scope', '=', 'Merge Payment')
                ->where('dts.status', 'Unpaid')
                ->whereIn('dts.id_trx_debitur', $debiturIds)
                ->groupBy(
                    'dpg.order_id',
                    'dpg.order_payment_token',
                    'dpg.order_payment_url',
                    'dts.tenor_sequence',
                    'debitur_invoice_header.trx_no',
                    'debitur_invoice_header.total_amount'
                )
                ->get();

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



    public function cancelMidtransPayment(string $orderId)
    {
        try {
            return \Midtrans\Transaction::cancel($orderId);
        } catch (\Exception $e) {
            Log::error('Midtrans cancel failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getMidTransPayMentStatus(Request $req, $orderId)
    {
        try {
            $isProd    = (bool) config('midtrans.is_production', false);
            $baseUrl   = $isProd ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
            $serverKey = config('midtrans.server_key');

            $url = $baseUrl . '/v2/' . $orderId . '/status';

            $response = Http::withBasicAuth($serverKey, '')
                ->acceptJson()
                ->get($url);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Failed to get payment status',
                    'errors'  => $response->json('error_messages') ?? $response->json(),
                ], $response->status());
            }

            return response()->json([
                'message'        => 'Payment status retrieved successfully',
                'payment_status' => $response->json(),
            ], 200);
        } catch (Exception $e) {
            Log::error("Midtrans Payment Status Error", ['exception' => $e]);
            return response()->json([
                'success' => false,
                'message' => 'Error getMidTransPayMentStatus: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateKreditUsaha(Request $request, $trxNo)
    {
        //dd($request->all());
        $user = auth('sanctum')->user();
        $tenantMitraData = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('mitra_id', 'alias')
            ->first();

        if (!$tenantMitraData) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant mitra data not found.'
            ], 404);
        }

        $mitraAlias = $tenantMitraData->alias;

        try {
            $penjaminanTrx = PenjaminanTransaction::where('trx_no', $trxNo)->first();
            if (!$penjaminanTrx) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak ditemukan.'
                ], 404);
            }

            if ($penjaminanTrx->trx_status !== 'D') {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaksi tidak dapat diubah karena status bukan Draft.'
                ], 422);
            }

            $this->validate($request, [
                'data.noSuratPermohonan'  => 'required|string',
                'data.pks'                => 'required|string',
                'data.jenisProduk'        => 'required|string',
                'data.bank'               => 'required|string',
                'data.tglSuratPermohonan' => 'required|date',
                'data.spSplit'            => 'required|string',
                'data.bankCabang'         => 'nullable|string',
                'data.feeBasePercentage'  => 'nullable|numeric',
                'data.teksPenjaminanSp'   => 'nullable|string',
                'data.dataDebitur'        => 'nullable|array',
                'data.dataInstitution'    => 'nullable|array',
                'data.tariftarifPercentage' => 'nullable|numeric',
            ]);

            $newStatus = $request->data['trx_status'];

            // Validasi file
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

            // Validasi PKS
            $penjaminanPKSResponse = $this->getPenjaminanPKS();
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
            if (empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
                ], 500);
            }

            $isDraft = $newStatus === 'D';

            // Validasi debitur batch hanya saat submit
            $dataDebitur = [];
            if (!$isDraft) {
                $selectedPks = $request->data['selectedPks'];
                $dataDebitur = $request->input('data.dataDebitur', []);
                $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
                $dataDebitur = $result['dataDebitur'];
                if (!$result['success']) {
                    return response()->json($result, 422);
                }
            }

            DB::transaction(function () use ($request, $user, $trxNo, $penjaminanTrx, $isDraft, $dataDebitur, $mitraAlias) {
                $nowJakarta     = Carbon::now('Asia/Jakarta');
                $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
                $spSplit        = $request->boolean('data.spSplit');

                // Update PenjaminanTransaction
                $penjaminanTrx->update([
                    'sp_split'                 => $spSplit,
                    'no_surat_permohonan'      => $request->data['noSuratPermohonan'],
                    'tanggal_surat_permohonan' => $permohonanDate,
                    'trx_status'               => $isDraft ? 'D' : 'NA',
                    'updated_at'               => $nowJakarta,
                ]);


                $kreditUsahaTrx = KreditUsahaTransaction::where('trx_no', $trxNo)->first();
                $kreditUsahaTrx->update([
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

                    $lastLoan = TrxDebiturDefaultBase::lockForUpdate()
                        ->where('loan_number', 'like', $prefix . '%')
                        ->orderBy('loan_number', 'desc')
                        ->value('loan_number');
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
                    if (!empty($rowInstitutions)) {
                        Institution::insert($rowInstitutions);
                    }

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

                    if (!empty($rows)) {
                        TrxDebiturDefaultBase::insert($rows);
                    }

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

                    if (!empty($savedAttachments)) {
                        DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                    }

                    // Insert PenjaminanFlow
                    PenjaminanFlow::create([
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

            return response()->json([
                'success' => true,
                'message' => 'Data Kredit Usaha berhasil diupdate',
            ]);
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Update Kredit Usaha: ' . $ex->getMessage()
            ], 500);
        }
    }
}
