<?php

namespace App\Services\MultigunaService;

use App\Helper\AesHelper;
use App\Repositories\MultigunaRepository;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MultigunaService
{
    public function __construct(
        protected MultigunaRepository $repository
    ) {}

    public function getMultigunaDetailWithAttachments(string $trxNo)
    {
        $penjaminanDetail = $this->repository->getMultigunaDetail($trxNo);

        if (!$penjaminanDetail) {
            throw new Exception('Data not found.');
        }

        $rows = $this->repository->getMultigunaDebitur($penjaminanDetail->id_multiguna);
        $lampiran = $this->repository->getMultigunaLampiran($trxNo);

        if ($rows->isNotEmpty()) {
            $key = base64_decode(config('services.secure.key'));

            foreach ($rows as $row) {
                if ($row->birth_date) {
                    $row->birth_date = AesHelper::decrypt($row->birth_date, $key);
                }
                if ($row->nik) {
                    $row->nik = AesHelper::decrypt($row->nik, $key);
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
                if ($row->current_salary_amount) {
                    $row->current_salary_amount = AesHelper::decrypt($row->current_salary_amount, $key);
                }
                $row->attachments = [];
            }

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

                foreach ($rows as $row) {
                    if (!empty($row->nik) && $row->nik === $fileNik) {
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

        // Add flow multiguna
        $multigunaFlow = $this->repository->getMultigunaFlow($trxNo);
        if ($multigunaFlow->isNotEmpty()) {
            $penjaminanDetail->flowMultiguna = $multigunaFlow;
        }

        if ($rows->isNotEmpty()) {
            $penjaminanDetail->debiturMultiguna = $rows;
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
}

