<?php

namespace App\Http\Controllers\KURServices;

use App\Helpers\AesHelper;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\DebiturInvoiceHeader;
use App\Models\DebiturTenorSchedule;
use App\Models\Institution;
use App\Models\KURTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use App\Services\CreatioService;
use App\Services\KURService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KURTransactionController extends Controller
{
    private $kurService;
    public function __construct(KURService $service)
    {
        $this->kurService = $service;
    }

    public function store(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            $mitraAlias = '';
            $tenantMitraData = $this->kurService->getTenantMitraData($user->mitra_id);
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
                // 'data.dataDebitur.*.attachments.nik' => 'nullable|string',
                'data.dataDebitur.*.debitur_kur.nomor_identitas_1' => 'nullable|string',
                'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
                'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.dataInstitution' => 'nullable|array',
                'data.tarifPercentage' => 'nullable|numeric',
            ]);
            if ($request->data['trx_status'] == 'D') {
                if ($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
                    throw new Exception('Excel tidak boleh diisi Jika dalam Ingin Save as Draft');
                }
            }
            $penjaminanPKSResponse = $this->getPenjaminanPKS($tenantMitraData->alias);
            $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
            if(empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
                throw new Exception($penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data');
            }
            if (empty($request->allFiles()) && $request->data['trx_status'] !== 'D') {
                throw new Exception('File upload wajib diisi (tidak ada file yang dikirim).', 422);
                // return response()->json([
                //     'success' => false,
                //     'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                // ], 422);
            }
            $selectedPks = $request->data['selectedPks'];
            $dataDebitur = $request->input('data.dataDebitur', []);
            $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
            $dataDebitur = $result['dataDebitur'];
            if (!$result['success']) {
                return response()->json($result, 422);
            }
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'Validation error',
                422,
                $e->errors()
            );
        } catch (Exception $ex) {
            return ApiResponse::error(
                $ex->getMessage(),
                $ex->getCode() ?? 500
            );
        }
    //     try {
    //         $user = auth('sanctum')->user();
    //         $mitraAlias = '';
    //         $tenantMitraData = $this->kurService->getTenantMitraData($user->mitra_id);
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
    //             // 'data.dataDebitur.*.attachments.nik' => 'nullable|string',
    //             'data.dataDebitur.*.debitur_kur.nomor_identitas_1' => 'nullable|string',
    //             'data.dataDebitur.*.attachments.uploads' => 'nullable|array',
    //             'data.dataDebitur.*.attachments.uploads.*' => 'nullablefile|mimes:pdf,jpg,jpeg,png|max:2048',
    //             'data.dataInstitution' => 'nullable|array',
    //             'data.tarifPercentage' => 'nullable|numeric',
    //         ]);
    //         if ($request->data['trx_status'] == 'D') {
    //             if ($request->has('data.dataDebitur') || $request->has('data.dataInstitution')) {
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'Excel tidak boleh diisi Jika dalam Ingin Save as Draft'
    //                 ], 500);
    //             }
    //         }

    //         $penjaminanPKSResponse = $this->getPenjaminanPKS($tenantMitraData->alias);
    //         $penjaminanPKSData = $penjaminanPKSResponse->getData(true);
    //         if(empty($penjaminanPKSData['Success']) || $penjaminanPKSData['Success'] !== true) {
    //             return response()->json([
    //                 'status' => 'error',
    //                 'message' => $penjaminanPKSData['Message'] ?? 'Failed to retrieve PKS data'
    //             ], 500);
    //         }
    //         if (empty($request->allFiles()) && $request->data['trx_status'] !== 'D') {
    //             throw new Exception('File upload wajib diisi (tidak ada file yang dikirim).', 422);
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
    //             ], 422);
    //         }

    //         $selectedPks = $request->data['selectedPks'];
    //         $dataDebitur = $request->input('data.dataDebitur', []);
    //         $result = $this->validateDebiturBatch($selectedPks, $penjaminanPKSData, $dataDebitur);
    //         $dataDebitur = $result['dataDebitur'];
    //         if (!$result['success']) {
    //             return response()->json($result, 422);
    //         }
    //         DB::transaction(function () use ($request, &$user, &$dataDebitur, $mitraAlias) {
    //             $currentYear = date('Y');
    //             $currentMonth = date('m');
    //             $lastTrx = PenjaminanTransaction::lockForUpdate()
    //                 ->where('trx_no', 'like', 'PNJ-' . $currentYear . '-' . $currentMonth . '%')
    //                 ->orderBy('trx_no', 'desc')
    //                 ->value('trx_no');
    //             if ($lastTrx) {
    //                 $lastSequence = intval(substr($lastTrx, -4));
    //                 $nextSeq = $lastSequence + 1;
    //             } else {
    //                 $nextSeq = 1;
    //             }

    //             $trxNo = 'PNJ-' . $currentYear . '-' . $currentMonth . '-' . str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    //             $permohonanDate = Carbon::parse($request->data['tglSuratPermohonan'])->format('Y-m-d');
    //             $nowJakarta = Carbon::now('Asia/Jakarta');
    //             $spSplit = $request->boolean('data.spSplit');
    //             PenjaminanTransaction::create([
    //                 'trx_no' => $trxNo,
    //                 'sp_split' => $spSplit,
    //                 'no_surat_permohonan' => $request->data['noSuratPermohonan'],
    //                 'tanggal_surat_permohonan' => $permohonanDate,
    //                 'trx_status' => $request->data['trx_status'],
    //                 'status_sync_creatio' => 0,
    //                 'created_by_name' => $user->name,
    //                 'created_at' => $nowJakarta,
    //                 'created_by_id' => $user->user_id,
    //                 'product' => 'kur',
    //                 'mitra_id' => $mitraAlias,
    //                 'no_rek' => '012312'
    //             ]);

    //             $kur = KURTransaction::create([
    //                 'trx_no' => $trxNo,
    //                 'jenis_product_description' => 'Kredit Usaha Rakyat',
    //                 'pks_number' => $request->data['pks'],
    //                 'fee_base_number' => $request->data['feeBasePercentage'],
    //                 'fee_base_percentage' => $request->data['feeBasePercentage'],
    //                 'bank_name' => $request->data['bankCabang'],
    //                 'bank_code' => $request->data['bank'],
    //                 'text_certified' => $request->data['teksPenjaminanSp'],
    //                 'created_at' => $nowJakarta
    //             ]);
    //             $kurId = $kur->getKey();
    //             $mitraId = $mitraAlias;
    //             $prefix = $mitraId . $currentYear;
    //             $lastLoan = TrxDebiturDefaultBase::lockForUpdate()
    //                 ->where('loan_number', 'like', $prefix . '%')
    //                 ->orderBy('loan_number', 'desc')
    //                 ->value('loan_number');
    //             $startSeq = 1;
    //             if ($lastLoan) {
    //                 $lastSeq = (int) substr($lastLoan, -4);
    //                 $startSeq = $lastSeq + 1;
    //             }

    //             $institutionMap = [];
    //             $key = base64_decode(config('services.secure.key'));
    //             $hashKey = config('services.secure.hash_key');
    //             $rowInstitution = collect(data_get($request->data, 'dataInstitution', []))
    //                 ->pluck('institution_data')
    //                 ->filter()
    //                 ->map(function ($value) use ($nowJakarta, &$institutionMap, &$user, $key, $hashKey) {
    //                     $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
    //                     $nik = $value['id_number'] ?? null;
    //                     $instId = (string) Str::uuid();
    //                     $nikHashed = hash_hmac('sha256', $nik, $hashKey);
    //                     if($nik) {
    //                         $institutionMap[$nik] = $instId;
    //                     }
    //                     return [
    //                         'category' => 'P',
    //                         'mitra_id' => 'MDR',
    //                         'tenant_id' => '2185e11e-35a6-4c89-aa3f-4645451e0536',
    //                         'id_issued_location' => '-',
    //                         'id_issued_location' => '-',
    //                         'id_add_issued_location' => '-',
    //                         'id_add_type' => "-",
    //                         'created_by' => $user->user_id,
    //                         'full_name' => $value['full_name'] ?? null,
    //                         'home_province' => $value['home_province'] ?? null,
    //                         'home_city' => $value['home_city'] ?? 0,
    //                         'home_district' => $value['home_district'] ?? null,
    //                         'home_sub_district' => $value['home_sub_district'] ?? null,
    //                         'home_zipcode' => $value['home_zipcode'] ?? null,
    //                         'birth_place' => $value['birth_place'] ?? null,
    //                         'birth_date' => $enc($value['birth_date'] ?? null),
    //                         'gender' => $value['gender'] ?? null,
    //                         'id_type' => $value['id_type'] ?? null,
    //                         'id_number' => $enc($nik),
    //                         'id_number_hash' => $nikHashed,
    //                         'job_id' => $value['job_id'] ?? null,
    //                         'job_level' => $value['job_level'] ?? null,
    //                         'job_employer_name' => $value['job_employer_name'] ?? null,
    //                         'job_start_date' => $value['job_start_date'] ?? null,
    //                         'job_industry_type' => $value['job_industry_type'] ?? null,
    //                         'current_salary_amount' => $enc($value['current_salary_amount'] ?? null),
    //                         'phone_1'    => $enc($value['phone_1'] ?? null),
    //                         'email_1'    => $enc($value['email_1'] ?? null),
    //                         'tax_id' => $enc($value['npwp']),
    //                         'current_salary_currency' => $value['current_salary_currency'],
    //                         'tax_type' => 'npwp',
    //                         'institution_id' => $instId,
    //                         'created_at' => $nowJakarta
    //                     ];
    //                 })
    //                 ->values()
    //                 ->all();
    //             if(!empty($rowInstitution)) {
    //                 Institution::insert($rowInstitution);
    //             }
    //             $countDebitur = count($dataDebitur);
    //             // dd($institutionMap);
    //             $rows = collect($dataDebitur)
    //                 ->pluck('debitur_kur')
    //                 ->filter()
    //                 ->map(function (array $d, int $idx) use ($request, $kurId, $nowJakarta, $prefix, $startSeq, $institutionMap, $key, $countDebitur) {
    //                     $enc = fn($v) => $v ? AesHelper::encrypt($v, $key) : null;
    //                     $spSequence = $idx + 1;
    //                     $baseSp = $request->data['noSuratPermohonan'];
    //                     $realisasi = Carbon::parse($d['tanggal_realisasi']);
    //                     $jatuhTempo = Carbon::parse($d['tanggal_jatuh_tempo']);
    //                     $jwBulan   = (int) ($d['jw_bulan'] ?? 0);
    //                     $tglAkhir = $realisasi->copy()->addMonthsNoOverflow($jwBulan);
    //                     $seq = $startSeq + $idx;
    //                     $loanNumber = $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    //                     // $nik = $d['nik'] ?? null;
    //                     $nik = $d['nomor_identitas_1'] ?? null;
    //                     return [
    //                         'kur_trx_id' => $kurId,
    //                         'nama_nasabah' => $d['debitur_name'] ?? null,
    //                         'alamat_nasabah' => $d['debitur_address'] ?? null,
    //                         'penggunaan_kredit' => $d['penggunaan_kredit'] ?? null,
    //                         'plafond_kredit' => $d['plafond_kredit'] ?? 0,
    //                         'nilai_penjaminan' => $d['nilai_penjaminan'] ?? 0,
    //                         'tanggal_usia' => $d['tgl_lahir'],
    //                         'instansi' => $d['instansi'] ?? null,
    //                         'suku_bunga' => $d['suku_bunga'] ?? null,
    //                         'jangka_waktu' => $d['jangka_waktu'] ?? null,
    //                         'tanggal_realisasi' => $d['tanggal_realisasi'] ?? null,
    //                         'tanggal_jatuh_tempo' => $d['tanggal_jatuh_tempo'] ?? null,
    //                         'jenis_agunan' => $d['jenis_agunan'] ?? null,
    //                         'nilai_agunan' => $d['nilai_agunan'] ?? null,
    //                         'tenaga_kerja' => $d['tenaga_kerja'] ?? null,
    //                         'jenis_terjamin' => $d['jenis_terjamin'] ?? null,
    //                         'ijp' => $d['ijp'] ?? null,
    //                         'loan_number' => $loanNumber,
    //                         'base_plafond' => $d['base_plafond'] ?? null,
    //                         'jenis_kredit' => $d['jenis_kredit'] ?? null,
    //                         'sp3' => $d['sp3'] ?? null,
    //                         'jenis_penjaminan' => $d['jenis_penjaminan'] ?? null,
    //                         'status_debitur' => $d['status_debitur'] ?? null,
    //                         'limit_penarikan' => $d['limit_penarikan'] ?? null,
    //                         'npwp_principal' => $d['npwp_giro'] ??null,
    //                         'no_sp_detail' => $countDebitur > 1 ? $baseSp . '-' . $spSequence : null,
    //                         // 'no_sp_detail' => $d['nilai_agunan'] ?? null,
    //                         // 'no_sp_core_debitur' => $d['nilai_agunan'] ?? null,
    //                         'institution_id' => $nik ? ($institutionMap[$nik] ?? null) : null,
    //                         'created_at' => $nowJakarta
    //                     ];
    //                 })
    //                 ->values()
    //                 ->all();
    //             // dd($rows);
    //             if(!empty($rows)) {
    //                 TrxDebiturDefaultBase::insert($rows);
    //             }

    //             $allFiles = $request->allFiles();
    //             $debiturFiles = data_get($allFiles, 'data.dataDebitur', []);
    //             $debiturInputs = $request->input('data.dataDebitur', []);
    //             $savedAttachments = [];
    //             $kurAttachmentFolder = 'uploads/penjaminan/kur';
    //             foreach($debiturFiles as $idx => $attachments) {
    //                 $nik = data_get($debiturInputs, "{$idx}.debitur_kur.nomor_identitas_1")
    //                     ?? data_get($debiturInputs, "{$idx}.attachments.nomor_identitas_1")
    //                     ?? 'UNKNOWN_NIK';
                    
    //                 foreach($attachments as $fileKey => $fileOrArray) {
    //                     if(is_array($fileOrArray)) {
    //                         foreach($fileOrArray as $innerKey => $file) {
    //                             if($file instanceof \Illuminate\Http\UploadedFile) {
    //                                 $ext = $file->getClientOriginalExtension();
    //                                 $unique = uniqid();
    //                                 $fn = "{$nik}-{$innerKey}-kur-{$unique}";
    //                                 $path = $file->storeAs(
    //                                     $kurAttachmentFolder,
    //                                     $fn . "." . $ext,
    //                                     's3'
    //                                 );

    //                                 $savedAttachments[] = [
    //                                     'trx_no' => $trxNo,
    //                                     'lampiran_id' => $innerKey,
    //                                     'file_name' => $fn,
    //                                     // 'file_info' => $file->getClientOriginalName(),
    //                                     'status_doc' => 'N',
    //                                     'version' => 1,
    //                                     'mime_type' => $file->getMimeType(),
    //                                     'file_info' => $path,
    //                                     'created_at' => $nowJakarta
    //                                 ];
    //                             }
    //                         }
    //                     } else {
    //                         $file = $fileOrArray;
    //                         if($file instanceof \Illuminate\Http\UploadedFile) {
    //                             $ext = $file->getClientOriginalExtension();
    //                             $unique = uniqid();
    //                             $fn = "{$trxNo}-ktp-kur-{$idx}-{$fileKey}";
    //                             $path = $file->storeAs(
    //                                 $kurAttachmentFolder,
    //                                 $fn . "." . $ext,
    //                                 's3'
    //                             );

    //                             $savedAttachments[] = [
    //                                 'trx_no' => $trxNo,
    //                                 'lampiran_id' => $fileKey,
    //                                 'file_name' => $fn,
    //                                 // 'file_info' => $file->getClientOriginalName(),
    //                                 'status_doc' => 'N',
    //                                 'version' => 1,
    //                                 'mime_type' => $file->getMimeType(),
    //                                 'file_info' => $path,
    //                                 'created_at' => $nowJakarta
    //                             ];
    //                         }
    //                     }
    //                 }
    //             }
    //             if(!empty($savedAttachments)) {
    //                 DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
    //             }
    //         });
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Data berhasil disubmit',
    //         ]);
    //     } catch (ValidationException $e) {
    //         return ApiResponse::error(
    //             'Validation error',
    //             422,
    //             $e->errors()
    //         );
    //     } catch (Exception $ex) {
    //         return ApiResponse::error(
    //             $ex->getMessage(),
    //             500
    //         );
    //     }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        dd("kkuu");
        if (empty($id)) {
            return response()->json([
                'success' => false,
                'message' => 'ID is required.'
            ], 400);
        }
        $trx_no = $id;
        try {
            $penjaminanDetail = $this->kurService->showKURDetail($trx_no);
            return ApiResponse::success($penjaminanDetail, 'Get detail penjaminan successful.');
        } catch (Exception $ex) {
            return response()->json([
                'success' => false,
                'message' => 'Error While Get Data KUR: ' . $ex->getMessage()
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
            $dataInstitution = $request->input('data.dataInstitution', []);

            if($request->data['trx_status'] == 'D' && (!empty($dataDebitur) || !empty($dataInstitution))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot fill debitur data when save penjaminan as draft.'
                ], 400);
            }
            else if(!empty($dataDebitur)) {
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
            else if($request->data['trx_status'] != 'D') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update draft as submitted when debitur data is empty.'
                ], 400);
            }

            if (empty($request->allFiles()) && $request->data['trx_status'] !== 'D') {
                return response()->json([
                    'success' => false,
                    'message' => 'File upload wajib diisi (tidak ada file yang dikirim).',
                ], 422);
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

                // Get and lock KUR transaction
                $kur = KURTransaction::lockFOrUpdate()
                    ->where('trx_no', $trxNo)
                    ->firstOrFail();
                // update KUR transaction data
                $kur->update([
                    'pks_number' => $request->data['pks'],
                    'fee_base_number' => $request->data['feeBasePercentage'],
                    'fee_base_percentage' => $request->data['feeBasePercentage'],
                    'bank_name' => $request->data['bankCabang'],
                    'bank_code' => $request->data['bank'],
                    'text_certified' => $request->data['teksPenjaminanSp'],
                    'updated_at' => $nowJakarta,
                ]);
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
                    DB::table('penjaminan_lampiran_dtl')->where('trx_no', $trxNo)->delete();

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
                                $file = $fileOrArray;
                                if ($file instanceof \Illuminate\Http\UploadedFile) {
                                    $ext = $file->getClientOriginalExtension();
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
                        // 'created_at' => $nowJakarta,
                        'created_at' => now(),
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
                'message' => 'Error While Updating KUR: ' . $ex->getMessage()
            ], 500);
        }
    }

    public function getDetailPaymentKUR(Request $request)
    {
        $key = base64_decode(config('services.secure.key'));
        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $data = [];
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('kur_transaction as kur', 'tph.trx_no', '=', 'kur.trx_no')
                ->join('trx_debitur as td', 'kur.id_kur', '=', 'td.kur_trx_id')
                ->join('debitur_tenor_schedule as dts', 'td.id_trx_debitur', '=', 'dts.id_trx_debitur')
                ->join('institution as ins', 'td.institution_id', '=', 'ins.institution_id')
                ->where('tph.trx_no', $trx_no)
                ->where('dts.status', 'Pending')
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'kur.id_kur',
                    'td.id_trx_debitur',
                    'td.plafond_kredit',
                    // 'td.nik',
                    'ins.id_number',
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
                $decryptedIdNumber = AesHelper::decrypt($row->id_number, $key);

                $row->id_number = $decryptedIdNumber;
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

    public function getDetailSplitPaymentKUR(Request $request)
    {
        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);
            $dataHeader = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('kur_transaction as kur', 'tph.trx_no', '=', 'kur.trx_no')
                ->where('tph.trx_no', $trx_no)
                ->where('tph.no_surat_permohonan', $no_surat_permohonan)
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tph.*',
                    'kur.id_kur',
                ])
                ->first();

            if (!$dataHeader) {
                return response()->json(['message' => 'Data tidak ditemukan'], 404);
            }
            $dataDebitur = TrxDebiturDefaultBase::query()
                ->from('trx_debitur as td')
                ->join('institution as inst', 'td.institution_id', '=', 'inst.institution_id')
                ->where('td.kur_trx_id', $dataHeader->id_kur)
                ->select(
                    'td.id_trx_debitur',
                    'td.no_sp_detail',
                    'td.loan_number',
                    'td.tanggal_realisasi',
                    'inst.id_number',
                    'td.nama_nasabah'
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

    private function getPenjaminanPKS($mitra_alias)
    {
        try {
            $pksService = new CreatioService();
            $response = $pksService->request('get', '/0/rest/MasterData/GetPKS', [], [
                'MitraID' => $mitra_alias
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

    private function validateDebiturBatch(string $selectedPks, array $penjaminanPKSData, array $dataDebitur): array
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
            unset($rowDebitur['debitur_kur']['__raw']);
            $debiturName = $rowDebitur['debitur_kur']['debitur_name'];
            // $nik = $rowDebitur['debitur_kur']['nik'];
            $nik = $rowDebitur['debitur_kur']['nomor_identitas_1'];

            if (!in_array($debiturName, $terjaminNames, true) || !$nik || !isset($nikTerjaminSet[(string) $nik])) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    // 'nik' => $nik,
                    'nomor_identitas_1' => $nik,
                    // 'plafond_pembiayaan_rp' => $rowDebitur['debitur_kur']['plafond_pembiayaan_rp'],
                    // 'plafond_kredit' => $rowDebitur['debitur_kur']['plafond_kredit'],
                    // 'plafond_max_pembiayaan' => $rowDebitur['debitur_kur']['plafond_max_pembiayaan'],
                    // 'nilai_kafalah' => $rowDebitur['debitur_kur']['nilai_kafalah'],
                    // 'jenis_penjaminan' => $rowDebitur['debitur_kur']['jenis_penjaminan'],
                    // 'status_debitur' => $rowDebitur['debitur_kur']['status_debitur'],
                    'reason' => 'NIK and name does not registered on PKS'
                ];
                continue;
            }
            $tgl = data_get($rowDebitur, 'debitur_kur.tanggal_jatuh_tempo');

            $jatuhTempo = Carbon::createFromFormat('Y-m-d', (string) $tgl)->startOfDay();
            if (now()->startOfDay()->greaterThan($jatuhTempo)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_kur']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_kur']['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal jatuh tempo harus lebih dari hari ini ',
                ];
                continue;
            }
            $tgl2 = data_get($rowDebitur, 'debitur_kur.tanggal_realisasi');
            $tglRealisasi = Carbon::createFromFormat('Y-m-d', (string) $tgl2)->startOfDay();
            if (now()->startOfDay()->greaterThan($tglRealisasi)) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    'plafond_kredit' => $rowDebitur['debitur_kur']['plafond_kredit'] ?? null,
                    'nilai_penjaminan' => $rowDebitur['debitur_kur']['nilai_penjaminan'] ?? null,
                    'reason' => 'Tanggal Realisasi harus lebih dari hari ini ',
                ];
                continue;
            }
            // Continue with other validation and logic
            $maks = ($nik && isset($maksPlafondByNik[(string) $nik]))
                ? $maksPlafondByNik[(string) $nik]
                : 0;

            // Update plafond max if needed
            if (isset($dataDebitur[$i]['debitur_kur'])) {
                $dataDebitur[$i]['debitur_kur']['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i]['debitur_kur'])) {
                    unset($dataDebitur[$i]['debitur_kur']['maksimal_nilai_plafond']);
                }
            } else {
                $dataDebitur[$i]['plafond_max_pembiayaan'] = $maks;
                if (array_key_exists('maksimal_nilai_plafond', $dataDebitur[$i])) {
                    unset($dataDebitur[$i]['maksimal_nilai_plafond']);
                }
            }
            $nilaiKafalah = ($rowDebitur['debitur_kur']['plafond_kredit'] * ($riskPercentage / 100));
            $rowDebitur['debitur_kur']['nilai_penjaminan'] = $nilaiKafalah;
            $rowDebitur['debitur_kur']['jenis_penjaminan'] = ($rowDebitur['debitur_kur']['plafond_kredit'] > $maxAmount) ? 'CBC' : 'CAC';
            $rowDebitur['debitur_kur']['status_debitur'] = ($rowDebitur['debitur_kur']['plafond_kredit'] > $maxAmount) ? 'Submitted' : 'Approved';
            $plafondPembiayaan = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_kur']['plafond_kredit']);
            $plafondMax = (int) preg_replace('/[^0-9]/', '', $rowDebitur['debitur_kur']['plafond_max_pembiayaan']);
            if ($plafondPembiayaan > $plafondMax) {
                $invalid[] = [
                    'debitur_name' => $debiturName,
                    'nik' => $nik,
                    // 'plafond_pembiayaan_rp' => $rowDebitur['debitur_kur']['plafond_pembiayaan_rp'],
                    'plafond_kredit' => $rowDebitur['debitur_kur']['plafond_kredit'],
                    'plafond_max_pembiayaan' => $rowDebitur['debitur_kur']['plafond_max_pembiayaan'],
                    'nilai_penjaminan' => $nilaiKafalah,
                    'jenis_penjaminan' => $rowDebitur['debitur_kur']['jenis_penjaminan'],
                    'status_debitur' => $rowDebitur['debitur_kur']['status_debitur'],
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
}