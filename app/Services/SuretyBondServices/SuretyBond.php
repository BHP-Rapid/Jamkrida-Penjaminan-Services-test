<?php

namespace App\Services\SuretyBondServices;

use App\Helpers\AesHelper;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\SuretyBondTenorSchedule;
use App\Models\TenantMitra;
use App\Services\InstitutionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\SuretyBondTransaction;
use App\Models\TrxSrtbInvoiceHeader;
use App\Models\TrxSrtbPaymentGateway;
use App\Services\CreatioService;
use App\Services\PenjaminanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SuretyBond
{
    public function handleShow($request)
    {
        $user = auth('sanctum')->user();

        $mitraCode = TenantMitra::where('mitra_id', $user->mitra_id)
            ->select('alias')->first();

        if ($mitraCode == null) {
            return [
                'status' => 404,
                'response' => [
                    'success' => false,
                    'message' => 'No mitra code found.'
                ]
            ];
        }

        $trx_no = $request->query('trx_no');

        $penjaminanData = PenjaminanTransaction::join(
            'surety_bond_transaction as sbt',
            'transaction_penjaminan_header.trx_no',
            '=',
            'sbt.trx_no'
        )
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->select(
                'transaction_penjaminan_header.trx_no',
                'transaction_penjaminan_header.trx_status',
                'transaction_penjaminan_header.no_surat_permohonan',
                'transaction_penjaminan_header.tanggal_surat_permohonan',
                'transaction_penjaminan_header.sp_split',
                'transaction_penjaminan_header.product',
                'sbt.jenis_bond',
                'sbt.jenis_bond_description',
                'sbt.jenis_persyaratan',
                'sbt.skema_penalty',
                'sbt.sektor',
                'sbt.id_institution',
                'sbt.principal_name',
                'sbt.obligee_name',
                'sbt.is_bast',
                'sbt.no_surat_bast',
                'sbt.bast_date',
                'sbt.project_name',
                'sbt.project_amount',
                'sbt.amount_bond',
                'sbt.bond_percentage',
                'sbt.start_period_date',
                'sbt.end_period_date',
                'sbt.total_day',
                'sbt.province',
                'sbt.tgl_surat_perjanjian',
                'sbt.no_surat_perjanjian',
                'sbt.jenis_surat_perjanjian',
                'sbt.tarif_percentage',
                'sbt.agunan_amount',
                'transaction_penjaminan_header.created_at',
                'transaction_penjaminan_header.updated_at'
            )->first();

        if (!$penjaminanData) {
            return [
                'status' => 404,
                'response' => [
                    'success' => false,
                    'message' => 'Data not found.'
                ]
            ];
        }

        $penjaminanData->institution = $this->getInstitution($penjaminanData->id_institution);

        $penjaminanData->history = $this->getHistory($trx_no);

        $penjaminanData->lampiran = $this->getLampiran($trx_no);

        return [
            'status' => 200,
            'response' => [
                'success' => true,
                'data' => $penjaminanData
            ]
        ];
    }

    private function getInstitution($institutionId)
    {
        $key = base64_decode(config('services.secure.key'));

        $institutionData = DB::table('institution as a')
            ->join('surety_bond_transaction as b', 'a.id', '=', 'b.id_institution')
            ->where('b.id_institution', $institutionId)
            ->select('b.*', 'a.*')
            ->first();

        if ($institutionData) {
            $institutionData->phone_1 = !empty($institutionData->phone_1)
                ? AesHelper::decrypt($institutionData->phone_1, $key)
                : null;

            $institutionData->email_1 = !empty($institutionData->email_1)
                ? AesHelper::decrypt($institutionData->email_1, $key)
                : null;

            $institutionData->birth_date = !empty($institutionData->birth_date)
                ? AesHelper::decrypt($institutionData->birth_date, $key)
                : null;

            $institutionData->id_number = !empty($institutionData->id_number)
                ? AesHelper::decrypt($institutionData->id_number, $key)
                : null;

            $institutionData->tax_id = !empty($institutionData->tax_id)
                ? AesHelper::decrypt($institutionData->tax_id, $key)
                : null;

            $institutionData->current_salary_amount = !empty($institutionData->current_salary_amount)
                ? AesHelper::decrypt($institutionData->current_salary_amount, $key)
                : null;

            $institutionData->other_income_amount = !empty($institutionData->other_income_amount)
                ? AesHelper::decrypt($institutionData->other_income_amount, $key)
                : null;
        }

        return $institutionData;
    }

    private function getHistory($trx_no)
    {
        return PenjaminanFlow::where('trx_no', $trx_no)
            ->orderBy('created_at', 'desc')
            ->select(
                'id',
                'trx_no',
                'trx_status',
                'reason',
                'additional_document',
                'status',
                'created_at',
                'updated_at',
                'created_by_id',
                'created_by_name'
            )->get();
    }

    private function getLampiran($trx_no)
    {
        $lampiranMax = PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select(
                'trx_no',
                'lampiran_id',
                DB::raw('MAX(version) as latest_version')
            )
            ->groupBy('trx_no', 'lampiran_id');

        $lampiranLatest = PenjaminanLampiranDtl::joinSub(
            $lampiranMax,
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

        $docList = DB::table('setting_hdr as a')
            ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
            ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
            ->leftJoinSub($lampiranLatest, 'lt', function ($join) {
                $join->on('lt.lampiran_id', '=', 'c.value');
            })
            ->select(
                'c.value',
                'c.label',
                'c.option2',
                'lt.lampiran_id',
                'lt.file_name',
                'lt.file_info',
                'lt.is_additional',
                'lt.status_doc',
                'lt.mime_type',
                'lt.version'
            )
            ->where('a.module', 'PENJAMINAN_SETTINGS')
            ->where('b.product_id', 'srtb')
            ->where('a.mitra_id', 'MDR')
            ->where('b.is_mandatory', 1)
            ->where('c.key', 'lampiran')
            ->whereNotNull('b.lampiran')
            ->orderBy('c.value', 'asc')
            ->get()
            ->toArray();

        return array_map(function ($item) {
            $fileUrl = null;
            $filePath = "";

            if ($item->lampiran_id != null) {
                $decodedInfo = json_validate($item->file_info)
                    ? json_decode($item->file_info)
                    : null;

                $filePath = $decodedInfo != null && $decodedInfo->path
                    ? $decodedInfo->path
                    : $item->file_info;

                $fileUrl = Storage::disk('s3')->temporaryUrl(
                    $filePath,
                    now()->addMinutes(15)
                );
            }

            return [
                'key_lampiran' => $item->value,
                'label_lampiran' => $item->label,
                'option_type' => $item->option2,
                'file_name' => $item->file_name,
                'file_path' => $filePath,
                'is_additional' => $item->is_additional,
                'status_doc' => $item->status_doc,
                'mime_type' => $item->mime_type,
                'presigned_url' => $fileUrl
            ];
        }, $docList);
    }

    public function handleStore($request)
    {
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

        $this->validateBase($request);
        $this->validateByStatus($request);

        $penjaminanPayload = collect($request->data)->toArray();

        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }

        $isBastPenjaminan = array_key_exists('isBast', $penjaminanPayload)
            ? $penjaminanPayload['isBast']
            : false;

        if ($isBastPenjaminan) {
            $this->validateBastStore($request);
        }

        [$hasLampiran, $duplicateLampiranId] = $this->validateLampiran($penjaminanPayload);

        if ($duplicateLampiranId) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate lampiran id.'
            ], 422);
        }

        $institutionService = new InstitutionService();
        $institutionPayload = $this->buildInstitutionPayload($request);

        $trxInsertStatus = $penjaminanPayload['status'] == 'submit' ? 'NA' : 'D';

        $institutionIsInserted = false;

        try {
            $institutionService->insertInstitution($institutionPayload, $user->user_id);
            $institutionIsInserted = true;
        } catch (ValidationException $ve) {
            return response()->json([
                'message' => $ve->getMessage(),
                'error' => $ve->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error inserting institution (' . $e->getMessage() . ')'
            ], 500);
        }

        DB::beginTransaction();

        try {
            $trxNo = $this->generateTrxNo();
            $nowJakarta = Carbon::now('Asia/Jakarta');

            $idInstitution = DB::table('institution')
                ->where('institution_id', $institutionService->getCreatedInstitutionId())
                ->select('id')->first();

            $fallback = function ($key, $default = null) use ($penjaminanPayload) {
                return (array_key_exists($key, $penjaminanPayload) && $penjaminanPayload[$key] != null)
                    ? $penjaminanPayload[$key]
                    : $default;
            };

            PenjaminanTransaction::create([
                'trx_no' => $trxNo,
                'no_surat_permohonan' => $fallback('noSuratPermohonan', 'DRAFT-' . $trxNo),
                'sp_split' => $fallback('isSplit'),
                'trx_status' => $trxInsertStatus,
                'status_sync_creatio' => 0,
                'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan', $nowJakarta),
                'created_by_name' => $user->name,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'no_rek' => '123',
                'mitra_id' => $mitraAlias,
                'product' => 'srtb'
            ]);

            SuretyBondTransaction::create(
                $this->buildSrtbPayload($penjaminanPayload, $fallback, $trxNo, $idInstitution)
            );

            if ($hasLampiran) {
                $attachments = $this->handleLampiran($penjaminanPayload, $trxNo);

                if (!empty($attachments)) {
                    DB::table('penjaminan_lampiran_dtl')->insert($attachments);
                }
            }

            PenjaminanFlow::create([
                'trx_no' => $trxNo,
                'trx_status' => $trxInsertStatus,
                'created_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name,
                'updated_at' => null
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Penjaminan Surety Bond successfully created."
            ]);
        } catch (Exception $e) {
            DB::rollBack();

            if ($institutionIsInserted) {
                DB::table('institution')
                    ->where('institution_id', $institutionService->getCreatedInstitutionId())
                    ->delete();
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function validateBase($request)
    {
        validator($request->all(), [
            'data.institution_data.full_name' => 'required|string|max:64',
            'data.status' => 'required|string|in:draft,submit',
            'data.jenisBond' => 'required|string|max:70'
        ])->validate();
    }

    private function validateByStatus($request)
    {
        if (strtolower($request->data['status']) == 'submit') {
            validator($request->all(), [
                'data.noSuratPermohonan' => 'required|string|max:50',
                'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
                'data.isSplit' => 'required|boolean',
                'data.jenisPernyataan' => 'required|string|max:50',
                'data.skemaPenalty' => 'required|string|max:50',
                'data.sektor' => 'required|string|max:50',
                'data.namaPrincipal' => 'required|string|max:255',
                'data.namaObligee' => 'required|string|max:255',
                'data.isBast' => 'required|boolean',
                'data.namaProyek' => 'required|string|max:100',
                'data.nilaiProyek' => 'required|numeric|min:0',
                'data.nilaiBond' => 'required|numeric|min:0',
                'data.nilaiBondPersentase' => 'required|numeric|min:0',
                'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
                'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
                'data.jangkaWaktu' => 'required|numeric|min:0',
                'data.propinsi' => 'required|string|max:50',
                'data.jenisSuratPerjanjian' => 'required|string|max:64',
                'data.noSuratPerjanjian' => 'required|string|max:64',
                'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
                'data.lampiran' => 'required|array|min:1',
                'data.lampiran.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
                'data.lampiran.*.lampiran_id' => 'required|string',
                'data.nilaiAgunan' => 'required|numeric|min:0',
            ])->validate();
        } else {
            validator($request->all(), [
                'data.noSuratPermohonan' => 'nullable|string|max:50',
                'data.lampiran.*.lampiran_id' => 'required|string',
            ])->validate();
        }
    }

    private function validateBastStore($request)
    {
        validator($request->all(), [
            'data.noSuratBast' => 'required|string|max:50',
            'data.tglSuratBast' => 'required|date'
        ])->validate();
    }

    private function validateLampiran($payload)
    {
        $hasLampiran = false;
        $duplicate = false;

        if (array_key_exists('lampiran', $payload) && !empty($payload['lampiran'])) {
            $ids = array_column($payload['lampiran'], 'lampiran_id');
            $duplicate = count($ids) !== count(array_unique($ids));
            $hasLampiran = true;
        }

        return [$hasLampiran, $duplicate];
    }

    private function buildInstitutionPayload($request)
    {
        $data = collect($request->data['institution_data'])->toArray();

        $data['category'] = 'P';
        $data['id_issued_location'] = '-';
        $data['phone_type'] = '-';

        return $data;
    }

    private function generateTrxNo()
    {
        $year = date('Y');
        $month = date('m');

        $last = PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', "PNJ-$year-$month%")
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');

        $next = $last ? intval(substr($last, -4)) + 1 : 1;

        return "PNJ-$year-$month-" . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    private function buildSrtbPayload($payload, $fallback, $trxNo, $idInstitution)
    {
        return [
            'trx_no' => $trxNo,
            'jenis_bond' => $payload['jenisBond'],
            'jenis_bond_description' => $payload['jenisBondDescription'],
            'jenis_persyaratan' => $fallback('jenisPernyataan'),
            'skema_penalty' => $fallback('skemaPenalty'),
            'sektor' => $fallback('sektor'),
            'principal_name' => $fallback('namaPrincipal'),
            'obligee_name' => $fallback('namaObligee'),
            'id_institution' => $idInstitution->id,
            'is_bast' => $fallback('isBast'),
            'no_surat_bast' => ($payload['isBast'] ?? false) ? $fallback('noSuratBast') : null,
            'bast_date' => ($payload['isBast'] ?? false) ? $fallback('tglSuratBast') : null,
            'project_name' => $fallback('namaProyek'),
            'project_amount' => $fallback('nilaiProyek'),
            'bond_percentage' => $fallback('nilaiBondPersentase'),
            'amount_bond' => $fallback('nilaiBond'),
            'start_period_date' => $fallback('periodeAwalBerlaku'),
            'total_day' => $fallback('jangkaWaktu'),
            'end_period_date' => $fallback('periodeAkhirBerlaku'),
            'province' => $fallback('propinsi'),
            'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
            'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
            'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
            'agunan_amount' => $fallback('nilaiAgunan'),
        ];
    }

    private function handleLampiran($payload, $trxNo)
    {
        $result = [];

        foreach ($payload['lampiran'] as $item) {
            $ext = $item['file']->getClientOriginalExtension();
            $fn = "{$trxNo}-{$item['lampiran_id']}-srtb-" . uniqid();

            $path = $item['file']->storeAs(
                'uploads/penjaminan/surety-bond',
                "$fn.$ext",
                's3'
            );

            $result[] = [
                'trx_no' => $trxNo,
                'lampiran_id' => $item['lampiran_id'],
                'file_name' => $fn,
                'status_doc' => 'N',
                'version' => 1,
                'mime_type' => $item['file']->getMimeType(),
                'file_info' => $path
            ];
        }

        return $result;
    }

    public function handleUpdate($request, $trxNo)
    {
        $user = auth('sanctum')->user();

        $this->validateUpdate($request);

        $penjaminanPayload = collect($request->data)->toArray();

        if (array_key_exists('institution_data', $penjaminanPayload)) {
            unset($penjaminanPayload['institution_data']);
        }

        [$lampiranExist, $duplicateLampiranId] = $this->validateLampiranEdit($penjaminanPayload);

        if ($duplicateLampiranId) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate lampiran id.'
            ], 422);
        }

        $isBast = $penjaminanPayload['isBast'] ?? false;

        if ($isBast) {
            $this->validateBastUpdate($request);
        }

        DB::beginTransaction();

        try {
            $header = PenjaminanTransaction::where('trx_no', $trxNo)
                ->select('trx_no', 'trx_status')
                ->first();

            $detail = SuretyBondTransaction::where('trx_no', $trxNo)
                ->select('id_trx_product')
                ->first();

            if ($header && $detail && $header->trx_status == 'D') {

                $fallback = function ($key, $default = null) use ($penjaminanPayload) {
                    return array_key_exists($key, $penjaminanPayload)
                        ? $penjaminanPayload[$key]
                        : $default;
                };

                $now = Carbon::now('Asia/Jakarta');

                PenjaminanTransaction::where('trx_no', $trxNo)
                    ->update([
                        'no_surat_permohonan' => $fallback('noSuratPermohonan'),
                        'tanggal_surat_permohonan' => $fallback('tglSuratPermohonan'),
                        'sp_split' => $fallback('isSplit'),
                        'updated_by_id' => $user->user_id,
                        'updated_by_name' => $user->name,
                        'updated_at' => $now
                    ]);

                SuretyBondTransaction::where('trx_no', $trxNo)
                    ->update($this->buildUpdateDetailPayload($penjaminanPayload, $fallback, $now));

                if ($lampiranExist) {
                    $attachments = $this->handleLampiranUpdate($penjaminanPayload, $trxNo);

                    if (!empty($attachments)) {
                        DB::table('penjaminan_lampiran_dtl')->insert($attachments);
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Penjaminan Surety Bond successfully updated.'
                ]);
            } else if ($header && $detail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data is not draft.'
                ], 422);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan data is not found.'
                ], 400);
            }
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function validateUpdate($request)
    {
        validator($request->all(), [
            'data.noSuratPermohonan' => 'required|string|max:50',
            'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
            'data.isSplit' => 'nullable|boolean',
            'data.jenisBond' => 'required|string|max:8',
            'data.jenisPernyataan' => 'nullable|string|max:50',
            'data.skemaPenalty' => 'nullable|string|max:50',
            'data.sektor' => 'nullable|string|max:50',
            'data.namaPrincipal' => 'nullable|string|max:255',
            'data.namaObligee' => 'nullable|string|max:255',
            'data.isBast' => 'nullable|boolean',
            'data.namaProyek' => 'nullable|string|max:100',
            'data.nilaiProyek' => 'nullable|numeric|min:0',
            'data.nilaiBond' => 'nullable|numeric|min:0',
            'data.nilaiBondPersentase' => 'nullable|numeric|min:0',
            'data.periodeAwalBerlaku' => 'nullable|date_format:Y-m-d',
            'data.periodeAkhirBerlaku' => 'nullable|date_format:Y-m-d',
            'data.jangkaWaktu' => 'nullable|numeric|min:0',
            'data.propinsi' => 'nullable|string|max:50',
            'data.jenisSuratPerjanjian' => 'nullable|string|max:64',
            'data.noSuratPerjanjian' => 'nullable|string|max:64',
            'data.tglSuratPerjanjian' => 'nullable|date_format:Y-m-d',
            'data.lampiranEdit' => 'nullable|array',
            'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
            'data.lampiranEdit.*.lampiran_id' => 'required|string',
        ])->validate();
    }

    private function validateBastUpdate($request)
    {
        validator($request->all(), [
            'data.noSuratBast' => 'required|string|max:50',
            'data.tglSuratBast' => 'required|date'
        ])->validate();
    }

    private function validateLampiranEdit($payload)
    {
        $exist = isset($payload['lampiranEdit']) && !empty($payload['lampiranEdit']);
        $duplicate = false;

        if ($exist) {
            $ids = array_column($payload['lampiranEdit'], 'lampiran_id');
            $duplicate = count($ids) !== count(array_unique($ids));
        }

        return [$exist, $duplicate];
    }

    private function buildUpdateDetailPayload($payload, $fallback, $now)
    {
        return [
            'jenis_bond' => $fallback('jenisBond'),
            'jenis_persyaratan' => $fallback('jenisPernyataan'),
            'skema_penalty' => $fallback('skemaPenalty'),
            'jenis_surat_perjanjian' => $fallback('jenisSuratPerjanjian'),
            'no_surat_perjanjian' => $fallback('noSuratPerjanjian'),
            'tgl_surat_perjanjian' => $fallback('tglSuratPerjanjian'),
            'sektor' => $fallback('sektor'),
            'principal_name' => $fallback('namaPrincipal'),
            'obligee_name' => $fallback('namaObligee'),
            'is_bast' => $fallback('isBast'),
            'no_surat_bast' => ($payload['isBast'] ?? false) ? $fallback('noSuratBast') : null,
            'bast_date' => ($payload['isBast'] ?? false) ? $fallback('tglSuratBast') : null,
            'project_name' => $fallback('namaProyek'),
            'project_amount' => $fallback('nilaiProyek'),
            'amount_bond' => $fallback('nilaiBond'),
            'bond_percentage' => $fallback('nilaiBondPersentase'),
            'start_period_date' => $fallback('periodeAwalBerlaku'),
            'end_period_date' => $fallback('periodeAkhirBerlaku'),
            'total_day' => $fallback('jangkaWaktu'),
            'province' => $fallback('propinsi'),
            'updated_at' => $now,
            'agunan_amount' => $fallback('nilaiAgunan'),
        ];
    }

    private function handleLampiranUpdate($payload, $trxNo)
    {
        $existing = PenjaminanLampiranDtl::where('trx_no', $trxNo)
            ->select('lampiran_id', DB::raw('MAX(version) as version'))
            ->groupBy('lampiran_id')
            ->get()
            ->toArray();

        $result = [];

        foreach ($payload['lampiranEdit'] as $item) {
            $ext = $item['file']->getClientOriginalExtension();
            $fn = "{$trxNo}-{$item['lampiran_id']}-srtb-" . uniqid();

            $path = $item['file']->storeAs(
                'uploads/penjaminan/surety-bond',
                "$fn.$ext",
                's3'
            );

            $searchIndex = array_search(
                $item['lampiran_id'],
                array_column($existing, 'lampiran_id')
            );

            $version = is_numeric($searchIndex)
                ? $existing[$searchIndex]['version'] + 1
                : 1;

            $result[] = [
                'trx_no' => $trxNo,
                'lampiran_id' => $item['lampiran_id'],
                'file_name' => $fn,
                'status_doc' => 'N',
                'version' => $version,
                'mime_type' => $item['file']->getMimeType(),
                'file_info' => $path
            ];
        }

        return $result;
    }

    public function handleSubmitDraft(Request $request, string $trxNo)
    {
        $user = auth('sanctum')->user();

        validator($request->all(), [
            'data.noSuratPermohonan' => 'required|string|max:50',
            'data.tglSuratPermohonan' => 'required|date_format:Y-m-d',
            'data.isSplit' => 'required|boolean',
            'data.jenisBond' => 'required|string|max:8',
            'data.jenisPernyataan' => 'required|string|max:50',
            'data.skemaPenalty' => 'required|string|max:50',
            'data.sektor' => 'required|string|max:50',
            'data.namaPrincipal' => 'required|string|max:255',
            'data.namaObligee' => 'required|string|max:255',
            'data.isBast' => 'required|boolean',
            'data.namaProyek' => 'required|string|max:100',
            'data.nilaiProyek' => 'required|numeric|min:0',
            'data.nilaiBond' => 'required|numeric|min:0',
            'data.nilaiBondPersentase' => 'required|numeric|min:0',
            'data.periodeAwalBerlaku' => 'required|date_format:Y-m-d',
            'data.periodeAkhirBerlaku' => 'required|date_format:Y-m-d',
            'data.jangkaWaktu' => 'required|numeric|min:0',
            'data.propinsi' => 'required|string|max:50',
            'data.jenisSuratPerjanjian' => 'required|string|max:64',
            'data.noSuratPerjanjian' => 'required|string|max:64',
            'data.tglSuratPerjanjian' => 'required|date_format:Y-m-d',
            'data.lampiranEdit' => 'nullable|array',
            'data.lampiranEdit.*.file' => 'file|mimes:pdf,jpg,jpeg,png|max:2048',
            'data.lampiranEdit.*.lampiran_id' => 'required|string',
        ])->validate();

        $payload = collect($request->data)->toArray();

        if (array_key_exists('institution_data', $payload)) {
            unset($payload['institution_data']);
        }

        $lampiranExist = array_key_exists('lampiranEdit', $payload) && $payload['lampiranEdit'];

        $duplicateLampiranId = false;

        if ($lampiranExist) {
            $ids = array_column($payload['lampiranEdit'], 'lampiran_id');
            $duplicateLampiranId = count(array_unique($ids)) != count($ids);
        }

        if ($duplicateLampiranId) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate lampiran id.'
            ], 422);
        }

        if ($payload['isBast'] == true) {
            validator($request->all(), [
                'data.noSuratBast' => 'required|string|max:50',
                'data.tglSuratBast' => 'required|date'
            ])->validate();
        }

        DB::beginTransaction();

        try {
            $header = PenjaminanTransaction::where('trx_no', $trxNo)
                ->select('trx_no', 'trx_status')->first();

            $detail = SuretyBondTransaction::where('trx_no', $trxNo)
                ->select('id_trx_product')->first();

            if ($header && $detail && $header->trx_status == 'D') {

                PenjaminanTransaction::where('trx_no', $trxNo)->update([
                    'no_surat_permohonan' => $payload['noSuratPermohonan'],
                    'tanggal_surat_permohonan' => $payload['tglSuratPermohonan'],
                    'sp_split' => $payload['isSplit'],
                    'trx_status' => 'NA',
                    'updated_by_id' => $user->user_id,
                    'updated_by_name' => $user->name
                ]);

                SuretyBondTransaction::where('trx_no', $trxNo)->update([
                    'jenis_bond' => $payload['jenisBond'],
                    'jenis_persyaratan' => $payload['jenisPernyataan'],
                    'skema_penalty' => $payload['skemaPenalty'],
                    'jenis_surat_perjanjian' => $payload['jenisSuratPerjanjian'],
                    'no_surat_perjanjian' => $payload['noSuratPerjanjian'],
                    'tgl_surat_perjanjian' => $payload['tglSuratPerjanjian'],
                    'sektor' => $payload['sektor'],
                    'principal_name' => $payload['namaPrincipal'],
                    'obligee_name' => $payload['namaObligee'],
                    'is_bast' => $payload['isBast'],
                    'no_surat_bast' => $payload['isBast'] ? $payload['noSuratBast'] : null,
                    'bast_date' => $payload['isBast'] ? $payload['tglSuratBast'] : null,
                    'project_name' => $payload['namaProyek'],
                    'project_amount' => $payload['nilaiProyek'],
                    'amount_bond' => $payload['nilaiBond'],
                    'bond_percentage' => $payload['nilaiBondPersentase'],
                    'start_period_date' => $payload['periodeAwalBerlaku'],
                    'end_period_date' => $payload['periodeAkhirBerlaku'],
                    'total_day' => $payload['jangkaWaktu'],
                    'province' => $payload['propinsi'],
                    'agunan_amount' => $payload['nilaiAgunan']
                ]);

                $savedAttachments = [];

                if ($lampiranExist) {
                    $existing = PenjaminanLampiranDtl::where('trx_no', $trxNo)
                        ->select('lampiran_id', DB::raw('MAX(version) as version'))
                        ->groupBy('lampiran_id')
                        ->get()
                        ->toArray();

                    foreach ($payload['lampiranEdit'] as $item) {
                        $file = $item['file'];
                        $ext = $file->getClientOriginalExtension();
                        $fn = "{$trxNo}-{$item['lampiran_id']}-srtb-" . uniqid();

                        $path = $file->storeAs(
                            'uploads/penjaminan/surety-bond',
                            "$fn.$ext",
                            's3'
                        );

                        $index = array_search(
                            $item['lampiran_id'],
                            array_column($existing, 'lampiran_id')
                        );

                        $version = is_numeric($index)
                            ? $existing[$index]['version'] + 1
                            : 1;

                        $savedAttachments[] = [
                            'trx_no' => $trxNo,
                            'lampiran_id' => $item['lampiran_id'],
                            'file_name' => $fn,
                            'status_doc' => 'N',
                            'version' => $version,
                            'mime_type' => $file->getMimeType(),
                            'file_info' => $path
                        ];
                    }
                }

                if (!empty($savedAttachments)) {
                    DB::table('penjaminan_lampiran_dtl')->insert($savedAttachments);
                }

                PenjaminanFlow::create([
                    'trx_no' => $trxNo,
                    'trx_status' => 'NA',
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'created_by_id' => $user->user_id,
                    'created_by_name' => $user->name,
                    'updated_at' => null
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Penjaminan Surety Bond successfully submitted.'
                ]);
            } elseif ($header && $detail) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data is not draft.'
                ], 422);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan surety bond data is not found.'
                ], 400);
            }
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function handleApprovePenjaminanSB(Request $request)
    {
        $trx_no = $request->trxNo;
        $user = auth('sanctum')->user();

        try {
            (new PenjaminanService())->approveSuretyBondPenjaminan(
                $trx_no,
                $user->user_id,
                $user->name,
                "Perorangan"
            );

            return 'Penjaminan Surety Bond successfully approved.';
        } catch (Exception $ex) {
            throw new Exception(
                'Error while approving Penjaminan Surety Bond (' . $ex->getMessage() . ')',
                500
            );
        }
    }

    public function handleGetDetailPaymentSrtb(Request $request)
    {
        $key = base64_decode(config('services.secure.key'));

        try {
            $no_surat_permohonan = $request->query('no_surat_permohonan');
            $trx_no              = $request->query('trx_no');
            $isSplit             = (int) $request->query('is_split', null);

            $data = [];
            $resultPending = [];

            $dataPending = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('surety_bond_transaction as sbt', 'tph.trx_no', '=', 'sbt.trx_no')
                ->join('institution as inst', 'sbt.id_institution', '=', 'inst.id')
                ->join('suretybond_tenor_schedule as srbs', 'sbt.id_trx_product', '=', 'srbs.id_trx_product')
                ->where('tph.trx_no', $trx_no)
                ->where(function ($subquery) {
                    $subquery->where('srbs.status', 'Pending')
                        ->orWhere('srbs.status_collateral', 'Pending');
                })
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'srbs.srtb_schedule_id',
                    'sbt.id_trx_product',
                    'inst.id_number',
                    'inst.id_type',
                    'inst.full_name',
                    'srbs.amount',
                    'srbs.invoice_number',
                    'srbs.invoice_number_collateral',
                    'srbs.collateral_amount',
                    'srbs.status_collateral',
                    'srbs.due_date',
                    'srbs.status',
                    'srbs.tenor_sequence'
                ])->get();

            foreach ($dataPending as $pending) {
                $decryptedIdNumber = AesHelper::decrypt($pending->id_number, $key);
                $numAmount = (int) $pending->amount;
                $numCollateralAmount = (int) $pending->collateral_amount;

                if ($pending->status == 'Pending') {
                    $resultPending[] = [
                        'schedule_id' => $pending->srtb_schedule_id,
                        'id_trx_product' => $pending->id_trx_product,
                        'id_number' => $decryptedIdNumber,
                        'id_type' => $pending->id_type,
                        'full_name' => $pending->full_name,
                        'amount' => $numAmount,
                        'invoice_number' => $pending->invoice_number,
                        'due_date' => $pending->due_date,
                        'status' => $pending->status,
                        'tenor_sequence' => $isSplit ? $pending->tenor_sequence : 0,
                        'is_collateral' => false
                    ];
                }

                if (
                    !empty($pending->invoice_number_collateral) &&
                    $pending->status_collateral == 'Pending'
                ) {
                    $resultPending[] = [
                        'schedule_id' => $pending->srtb_schedule_id,
                        'id_trx_product' => $pending->id_trx_product,
                        'id_number' => $decryptedIdNumber,
                        'id_type' => $pending->id_type,
                        'full_name' => $pending->full_name,
                        'amount' => $numCollateralAmount,
                        'invoice_number' => $pending->invoice_number_collateral,
                        'due_date' => $pending->due_date,
                        'status' => $pending->status_collateral,
                        'tenor_sequence' => $isSplit ? $pending->tenor_sequence : 0,
                        'is_collateral' => true
                    ];
                }
            }

            $dataUnpaid = PenjaminanTransaction::query()
                ->from('transaction_penjaminan_header as tph')
                ->join('surety_bond_transaction as sbt', 'tph.trx_no', '=', 'sbt.trx_no')
                ->join('institution as inst', 'sbt.id_institution', '=', 'inst.id')
                ->join('suretybond_tenor_schedule as srbs', 'sbt.id_trx_product', '=', 'srbs.id_trx_product')
                ->join('trx_srtb_invoice_header as tsih', 'tsih.srtb_schedule_id', '=', 'srbs.srtb_schedule_id')
                ->join('trx_srtb_payment_gateway as tspg', 'tspg.srtb_invoice_id', '=', 'tsih.srtb_invoice_id')
                ->where('tph.trx_no', $trx_no)
                ->where('tsih.status', 'Unpaid')
                ->where('tph.sp_split', $isSplit)
                ->select([
                    'tspg.order_id',
                    'tspg.srtb_payment_id as payment_id',
                    'tph.trx_no',
                    'tspg.payment_amount_ijp as total_amount',
                    'tspg.order_payment_token',
                ])
                ->get();

            return [
                'dataHeader' => [
                    'data_pending' => $resultPending,
                    'data_unpaid' => $dataUnpaid
                ]
            ];
        } catch (Exception $e) {
            Log::error("Error fetching payment details", [
                'exception' => $e,
                'trx_no' => $trx_no ?? null,
                'no_surat_permohonan' => $no_surat_permohonan ?? null
            ]);

            throw $e;
        }
    }

    public function handleUploadPembayaranManual(Request $request)
    {
        $user = auth('sanctum')->user();
        $nowJakarta = Carbon::now('Asia/Jakarta');

        validator($request->all(), [
            'trx_no' => 'required|string|max:50',
            'amount' => 'required|numeric',
            'selected_items' => 'required|string',
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf,doc,docx|max:10240'
        ])->validate();

        if (
            !json_validate($request->selected_items) ||
            !is_array(json_decode($request->selected_items))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid selected item data.'
            ], 422);
        }

        $parsedItem = json_decode($request->selected_items);

        $invoiceNumbers = collect($parsedItem)->pluck('invoice_number')->toArray();

        if (count($invoiceNumbers) != count(array_unique($invoiceNumbers))) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate invoice data.'
            ], 422);
        }

        $normalItems = collect($parsedItem)->where('is_collateral', '!=', true);
        $collateralItems = collect($parsedItem)->where('is_collateral', true);

        $arrNormalInvoice = $normalItems->pluck('invoice_number')->toArray();
        $arrCollateralInvoice = $collateralItems->pluck('invoice_number')->toArray();

        DB::beginTransaction();

        try {
            $header = PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->select('no_surat_permohonan')
                ->first();

            $tenorData = SuretyBondTenorSchedule::query()
                ->from('surety_bond_transaction as sbt')
                ->join('suretybond_tenor_schedule as srbs', 'sbt.id_trx_product', '=', 'srbs.id_trx_product')
                ->select([
                    'srbs.srtb_schedule_id',
                    'sbt.id_trx_product',
                    'sbt.trx_no',
                    'srbs.tenor_sequence',
                    'srbs.invoice_number',
                    'srbs.invoice_number_collateral',
                    'srbs.amount',
                    'srbs.collateral_amount',
                    'srbs.status',
                    'srbs.status_collateral'
                ])
                ->where(function ($q) use ($arrNormalInvoice, $arrCollateralInvoice) {
                    $q->where(function ($sub) use ($arrNormalInvoice) {
                        $sub->where('status', 'Pending')
                            ->whereIn('srbs.invoice_number', $arrNormalInvoice);
                    })->orWhere(function ($sub) use ($arrCollateralInvoice) {
                        $sub->where(function ($s) {
                            $s->whereNull('status_collateral')
                                ->orWhere('status_collateral', 'Pending');
                        })->whereIn('srbs.invoice_number_collateral', $arrCollateralInvoice);
                    });
                })
                ->where('sbt.trx_no', $request->trx_no)
                ->get();

            if ($tenorData->count() < 1 || !$header) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penjaminan Surety Bond not found or no payment data.'
                ], 404);
            }

            $noSP = $header->no_surat_permohonan;

            $headerPayments = [];
            $debiturPayload = [];
            $totalAmount = 0;

            foreach ($tenorData as $row) {

                $paymentScope = 'Permohonan Payment';
                $paymentAmount = 0;

                $collateralIndex = !empty($row->invoice_number_collateral)
                    ? array_search($row->invoice_number_collateral, array_column($parsedItem, 'invoice_number'))
                    : false;

                $permohonanIndex = array_search(
                    $row->invoice_number,
                    array_column($parsedItem, 'invoice_number')
                );

                if (is_numeric($collateralIndex)) {
                    $paymentScope = 'Collateral Payment';
                    $paymentAmount += (int) $row->collateral_amount;

                    $debiturPayload[] = [
                        'no_sp_detail' => $noSP,
                        'invoice_number' => $row->invoice_number_collateral,
                        'total_amount' => (int) $row->collateral_amount
                    ];
                }

                if (is_numeric($permohonanIndex)) {
                    $paymentScope = is_numeric($collateralIndex)
                        ? 'Merge Payment'
                        : $paymentScope;

                    $paymentAmount += (int) $row->amount;

                    $debiturPayload[] = [
                        'no_sp_detail' => $noSP,
                        'invoice_number' => $row->invoice_number,
                        'total_amount' => (int) $row->amount
                    ];
                }

                $totalAmount += $paymentAmount;

                $headerPayments[] = [
                    'srtb_schedule_id' => $row->srtb_schedule_id,
                    'id_trx_product' => $row->id_trx_product,
                    'trx_no' => $row->trx_no,
                    'tenor_sequence' => $row->tenor_sequence,
                    'invoice_number' => $row->invoice_number,
                    'invoice_number_collateral' => $row->invoice_number_collateral,
                    'amount' => $row->amount,
                    'collateral_amount' => $row->collateral_amount,
                    'invoice_scope' => $paymentScope,
                    'total_amount' => $paymentAmount,
                    'status' => is_numeric($permohonanIndex) ? 'Paid' : $row->status,
                    'status_collateral' => is_numeric($collateralIndex) ? 'Paid' : $row->status_collateral
                ];
            }

            if ($totalAmount != (int) $request->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incorrect amount.'
                ], 422);
            }

            $orderIds = [];

            foreach ($headerPayments as $item) {

                $invoice = TrxSrtbInvoiceHeader::create([
                    'srtb_schedule_id' => $item['srtb_schedule_id'],
                    'invoice_scope' => $item['invoice_scope'],
                    'total_amount' => $item['total_amount'],
                    'status' => 'Paid',
                    'is_manual' => 1
                ]);

                $orderId = 'ORDER-' . now()->format('YmdHis') . '-' . mt_rand(1000, 9999);

                TrxSrtbPaymentGateway::create([
                    'srtb_invoice_id' => $invoice->srtb_invoice_id,
                    'payment_amount_ijp' => $item['total_amount'],
                    'order_id' => $orderId
                ]);

                SuretyBondTenorSchedule::where('srtb_schedule_id', $item['srtb_schedule_id'])
                    ->update([
                        'status' => $item['status'],
                        'status_collateral' => $item['status_collateral']
                    ]);

                $orderIds[] = $orderId;
            }

            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $filename = $orderIds[0] . '-pembayaran-srtb';

            $base64 = base64_encode(file_get_contents($file->path()));

            $creatio = new CreatioService();

            $response = $creatio->request('post', '/0/rest/PembayaranWebService/PembayaranManualV2', [
                'NoSuratPermohonan' => $noSP,
                'ListDebitur' => $debiturPayload,
                'NamaFile' => "$filename.$ext",
                'DataBase64' => $base64
            ]);

            if ($response->status() !== 200) {
                throw new Exception("Failed send to Creatio. Status: " . $response->status());
            }

            $body = json_decode($response->body(), true);

            if ($body['Success'] !== true) {
                throw new Exception("Creatio Error: " . $body['Message']);
            }

            $path = $file->storeAs(
                'uploads/penjaminan/payment-surety-bond',
                "$filename.$ext",
                's3'
            );

            PenjaminanLampiranDtl::create([
                'trx_no' => $request->trx_no,
                'lampiran_id' => 'pembayaran',
                'file_name' => $filename,
                'status_doc' => 'N',
                'version' => 1,
                'mime_type' => $file->getMimeType(),
                'file_info' => $path
            ]);

            PenjaminanTransaction::where('trx_no', $request->trx_no)
                ->update([
                    'trx_status' => 'PD',
                    'updated_at' => $nowJakarta
                ]);

            PenjaminanFlow::insert([
                'trx_no' => $request->trx_no,
                'trx_status' => 'PD',
                'created_at' => $nowJakarta,
                'updated_at' => $nowJakarta,
                'created_by_id' => $user->user_id,
                'created_by_name' => $user->name
            ]);

            DB::commit();

            return 'Bukti bayar manual uploaded successfully.';
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception('Error upload bukti bayar manual (' . $e->getMessage() . ')', 500);
        }
    }
}
