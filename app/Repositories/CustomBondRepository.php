<?php

namespace App\Repositories;

use App\Models\CustomBondTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use Illuminate\Support\Facades\DB;

class CustomBondRepository
{

    public function getDetail($trx_no, $no_surat_permohonan)
    {

        $data = PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('custom_bond_transaction as cbt', 'tph.trx_no', '=', 'cbt.trx_no')
            ->where('tph.trx_no', $trx_no)
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->select('tph.*', 'cbt.*')
            ->first();

        if (!$data) {
            return null;
        }
        $institution = DB::table('institution as a')
            ->join('custom_bond_transaction as b', 'a.id', '=', 'b.id_institution')
            ->where('b.id_institution', $data->id_institution)
            ->select('a.*')
            ->first();

        $flow = PenjaminanFlow::where('trx_no', $trx_no)
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'data' => $data,
            'institution' => $institution,
            'flow' => $flow
        ];
    }

    public function getLampiranData($trx_no)
    {
        $penjaminanVersionMax = PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select('trx_no', 'lampiran_id', DB::raw('MAX(version) as latest_version'))
            ->groupBy('trx_no', 'lampiran_id');

        $lampiranLatest = PenjaminanLampiranDtl::joinSub(
            $penjaminanVersionMax,
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

        return DB::table('setting_hdr as a')
            ->join('setting_product_dtl as b', 'a.id', '=', 'b.hdr_id')
            ->join('mapping_value as c', 'b.lampiran', '=', 'c.value')
            ->leftJoinSub($lampiranLatest, 'lt', function ($join) {
                $join->on('lt.lampiran_id', '=', 'c.value');
            })
            ->select(
                'c.value',
                'c.label',
                'c.option2',
                'lt.file_name',
                'lt.file_info',
                'lt.is_additional',
                'lt.status_doc',
                'lt.mime_type'
            )
            ->where('a.module', 'PENJAMINAN_SETTINGS')
            ->where('b.product_id', 'cstb')
            ->where('a.mitra_id', 'MDR')
            ->where('b.is_mandatory', 1)
            ->where('c.key', 'lampiran')
            ->orderBy('c.value', 'asc')
            ->get();
    }

    public function getInstitutionId($institutionId)
    {
        return DB::table('institution')
            ->where('institution_id', $institutionId)
            ->select('id')
            ->first();
    }

    public function getLastTrx($year, $month)
    {
        return PenjaminanTransaction::lockForUpdate()
            ->where('trx_no', 'like', "PNJ-{$year}-{$month}%")
            ->orderBy('trx_no', 'desc')
            ->value('trx_no');
    }

    public function insertPenjaminanTransaction(array $data)
    {
        return PenjaminanTransaction::create($data);
    }

    public function insertCustomBondTransaction(array $data)
    {
        return CustomBondTransaction::create($data);
    }

    public function insertLampiran(array $data)
    {
        return PenjaminanLampiranDtl::create($data);
    }

    public function insertFlow(array $data)
    {
        return PenjaminanFlow::create($data);
    }

    public function getLampiranByFileName($fileName)
    {
        return PenjaminanLampiranDtl::where('file_name', $fileName)
            ->select('file_info')
            ->first();
    }

    public function getDraftData($trxNo)
    {
        $header = PenjaminanTransaction::where('trx_no', $trxNo)
            ->select('trx_no', 'trx_status')
            ->first();

        $bond = CustomBondTransaction::where('trx_no', $trxNo)
            ->select('id_bond')
            ->first();

        return [
            'header' => $header,
            'bond' => $bond
        ];
    }
    public function getTenantMitraData($mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }
}
