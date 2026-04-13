<?php

namespace App\Services\SuretyBondServices;

use App\Helpers\AesHelper;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Services\InstitutionService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\SuretyBondTransaction;
use Illuminate\Http\Request;

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
}
