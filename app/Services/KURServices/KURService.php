<?php

namespace App\Services;

use App\Helpers\AesHelper;
use App\Repositories\KURRepository;
use Exception;
use Illuminate\Support\Facades\Storage;

class KURService
{
    public function __construct(
        protected KURRepository $repository
    ) {

    }

    public function getTenantMitraData($mitra_id) {
        try {
            $result = $this->repository->getTenantMitraData($mitra_id);
            if(!$result) {
                throw new Exception("Tenant Mitra not found.", 404);
            }
            return $result;
        } catch (Exception $ex) {
            throw new Exception($ex->getMessage(), 500);
        }
    }

    public function showKURDetail($trx_no) {
        try {
            $penjaminanDetail = $this->repository->getPenjaminanDetail($trx_no);
            if(!$penjaminanDetail)
            {
                throw new Exception('Data not found.', 404);
            }
            $rows = $this->repository->getDebiturWithInstitution($penjaminanDetail->id_kur);
            $lampiran = $this->repository->getLampiranKURDetail($trx_no);
            if($rows->isNotEmpty()) {
                $key = base64_decode(config('services.secure.key'));
                foreach($rows as $row) {
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

                foreach($lampiran as $att) {
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
                                'presigned_url' => Storagege::disk('s3')->temporaryUrl(
                                    $att->file_info,
                                    now()->addMinutes(15)
                                ),
                            ];
                            $row->attachments[] = $item;
                        }
                    }
                }
            }
            $kurFlow = $this->repository->getKURFlow($trx_no);
            if ($kurFlow != null) {
                $penjaminanDetail->flowMultiguna = $kurFlow;
            }
            if ($rows != null) {
                $penjaminanDetail->debiturKur = $rows;
            }
            return $penjaminanDetail;
        } catch(Exception $ex) {
            throw new Exception($ex->getMessage(), 500);
        }
            
    }
}