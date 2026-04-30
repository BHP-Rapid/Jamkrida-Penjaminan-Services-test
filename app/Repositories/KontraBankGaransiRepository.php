<?php

namespace App\Repositories;

use App\Models\KBGTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use Illuminate\Support\Facades\DB;

class KontraBankGaransiRepository
{
    public function getTenantMitraData($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }

    public function deleteInstitutionData(array $institution_id_list)
    {
        DB::table('institution')
            ->whereIn('institution_id', $institution_id_list)
            ->delete();
    }

    public function getLastTrxNo(string $year, string $month)
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', 'PNJ-' . $year . '-' . $month . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function getIdInstitution(string $institution_guid)
    {
        return DB::table('institution')
            ->where('institution_id', $institution_guid)
            ->select('id')->first();
    }

    public function insertHeaderKbg($data)
    {
        PenjaminanTransaction::create($data);
    }

    public function insertTrxKbg($data)
    {
        KBGTransaction::create($data);
    }

    public function insertAttachmentsKbg(array $data)
    {
        DB::table('penjaminan_lampiran_dtl')->insert($data);
    }

    public function insertPenjaminanKbgFlow($trx_no, $status_code, $user, $status_approval = null)
    {
        PenjaminanFlow::create([
            'trx_no' => $trx_no,
            'trx_status' => $status_code,
            'created_at' => now(),
            'created_by_id' => $user->user_id,
            'created_by_name' => $user->name,
            'status'=> $status_approval,
            'updated_at' => null
        ]);
    }
}
