<?php

namespace App\Repositories;

use App\Models\Institution;
use App\Models\KURTransaction;
use App\Models\MappingValue;
use App\Models\NotifMitra;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\TrxDebiturDefaultBase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use function Symfony\Component\Clock\now;

class KURRepository
{
    public function getLastTrxNo($year, $month)
    {
        return PenjaminanTransaction::lockForUpdate()
            // ->where('trx_no', 'like', "PNJ-$year-$month%")
            ->where('trx_no', 'like', 'PNJ-' . $year . '-' . $month . '%')
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function getLastLoanNumber($mitra_id, $year)
    {
        $prefix = $mitra_id . $year;
        return TrxDebiturDefaultBase::lockForUpdate()
            ->where('loan_number', 'like', $prefix . '%')
            ->orderBy('loan_number', 'desc')
            ->value('loan_number');
    }

    public function getTenantMitraData($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }

    public function getInstitutionByListId($list_id)
    {
        return Institution::whereIn('institution_id', $list_id)
            ->get()
            ->keyBy('institution_id');
    }

    public function getPenjaminanDetail($trx_no)
    {
        return PenjaminanTransaction::join('kur_transaction as mt', 'transaction_penjaminan_header.trx_no', '=', 'mt.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->select('transaction_penjaminan_header.*', 'mt.*')
            ->first();
    }

    public function getDebiturKur($id_kur)
    {
        return TrxDebiturDefaultBase::where('kur_trx_id', $id_kur)->get();
    }

    public function getLampiranKur($trx_no)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select(
                'trx_no',
                'lampiran_id',
                'file_info',
                'mime_type'
            )->cursor();
    }

    public function getLampiranMappingKur($code_list)
    {
        return MappingValue::where('key', 'lampiran')
            ->whereIn('value', $code_list)
            ->select('value', 'option3')
            ->get();
    }

    public function getDebiturWithInstitution($id_kur)
    {
        return DB::table('institution as a')
        ->join('trx_debitur as b', 'a.institution_id', '=', 'b.institution_id')
            ->where('b.kur_trx_id', $id_kur)
            ->select('b.*', 'a.*')
            ->get();
    }

    public function getLampiranKURDetail($trx_no)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trx_no)->get();
    }

    public function getKURFlow($trx_no)
    {
        return PenjaminanFlow::where('trx_no', $trx_no)->orderBy('created_at', 'desc')->get();
    }

    public function getHeaderKurJoinTrx($trx_no)
    {
        return PenjaminanTransaction::join('kur_transaction as kur', 'transaction_penjaminan_header.trx_no', '=', 'kur.trx_no')
            ->where('transaction_penjaminan_header.trx_no', $trx_no)
            ->where('transaction_penjaminan_header.status_sync_creatio', 0)
            ->first();
    }

    public function insertHeaderKur($data)
    {
        return PenjaminanTransaction::create($data);
    }

    public function insertTrxKur($data)
    {
        return KURTransaction::create($data);
    }

    public function insertPenjaminanKurFlow($trx_no, $status_code, $user, $status_approval = null)
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

    public function insertNotifApprovalKur($trx_no, $user)
    {
        NotifMitra::create([
            'mitra_user_id' => $user->user_id,
            'title' => "Mitra Portal - Penjaminan Kredit Usaha Rakyat (KUR)",
            'message' => "Status penjaminan KUR dengan nomor " . $trx_no . " menjadi " . "Submitted",
        ]);
    }

    public function updateHeaderKur($trx_no, $payload)
    {
        $penjaminan = PenjaminanTransaction::lockForupdate()
            ->where('trx_no', $trx_no)
            ->firstOrFail();
        $penjaminan->update($payload);
    }

    public function updateTrxKur($trx_no, $payload)
    {
        $data = KURTransaction::lockFOrUpdate()
            ->where('trx_no', $trx_no)
            ->firstOrFail();
        $data->update($payload);
        return $data;
    }

    public function updateApprovalHeaderKur($trx_no, $status_code, $status_sync, $user)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        PenjaminanTransaction::where('trx_no', $trx_no)->update([
            'status_sync_creatio' => $status_sync,
            'trx_status' => $status_code,
            'updated_by_id' => $user->user_id,
            'updated_by_name' => $user->name,
            'updated_at' => $time_now_jakarta,
        ]);
    }

    public function deleteKurFlow($trx_no)
    {
        PenjaminanFlow::where('trx_no', $trx_no)->delete();
    }
}
