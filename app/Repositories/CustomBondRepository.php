<?php

namespace App\Repositories;

use App\Models\CustomBondTenorSchedule;
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

    public function getDraftData(string $trxNo)
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

    public function updatePenjaminanTransaction(string $trxNo, array $data)
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)->update($data);
    }

    public function updateCustomBondTransaction(string $trxNo, array $data)
    {
        return CustomBondTransaction::where('trx_no', $trxNo)->update($data);
    }

    public function getTenantMitraData(string $mitra_id)
    {
        return TenantMitra::where('mitra_id', $mitra_id)
            ->select('mitra_id', 'alias', 'tenant_id')
            ->first();
    }

    public function getPaymentHeader(string $trxNo)
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)
            ->where('trx_status', 'WFP')
            ->select('no_surat_permohonan')
            ->first();
    }

    public function getPendingTenorData(string $trxNo, array $invoiceNumbers)
    {
        return CustomBondTenorSchedule::query()
            ->from('custom_bond_transaction as cbt')
            ->join('custombond_tenor_schedule as cbs', 'cbt.id_bond', 'cbs.id_bond')
            ->select([
                'cbs.cstb_schedule_id',
                'cbt.id_bond',
                'cbt.trx_no',
                'cbs.tenor_sequence',
                'cbs.due_date',
                'cbs.invoice_number',
                'cbs.amount',
                'cbs.status'
            ])
            ->where('cbs.status', 'Pending')
            ->whereIn('cbs.invoice_number', $invoiceNumbers)
            ->where('cbt.trx_no', $trxNo)
            ->orderBy('cbs.cstb_schedule_id')
            ->get();
    }

    public function getDetailPaymentCstbPending(string $trx_no, string $no_surat_permohonan, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('custom_bond_transaction as cbt', 'tph.trx_no', '=', 'cbt.trx_no')
            ->join('institution as inst', 'cbt.id_institution', '=', 'inst.id')
            ->join('custombond_tenor_schedule as cts', 'cbt.id_bond', '=', 'cts.id_bond')
            ->where('tph.trx_no', $trx_no)
            ->where('cts.status', 'Pending')
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->when(!is_null($isSplit), function ($q) use ($isSplit) {
                $q->where('tph.sp_split', $isSplit);
            })
            ->select([
                'cts.cstb_schedule_id',
                'cts.id_bond',
                'inst.id_number',
                'inst.id_type',
                'inst.full_name',
                'cts.amount',
                'cts.invoice_number',
                'cts.due_date',
                'cts.status',
                'cts.tenor_sequence'
            ])
            ->first();
    }

    public function getDetailPaymentCstbUnpaid(string $trx_no, string $no_surat_permohonan, int  $isSplit)
    {
       return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('custom_bond_transaction as cbt', 'tph.trx_no', '=', 'cbt.trx_no')
            ->join('institution as inst', 'cbt.id_institution', '=', 'inst.id')
            ->join('custombond_tenor_schedule as cts', 'cts.id_bond', '=', 'cbt.id_bond')
            ->join('trx_cstb_invoice_header as tcih', 'tcih.cstb_schedule_id', '=', 'cts.cstb_schedule_id')
            ->join('trx_cstb_payment_gateway as tcpg', 'tcpg.cstb_invoice_id', '=', 'tcih.cstb_invoice_id')
            ->where('tph.trx_no', $trx_no)
            ->where('tcih.status', 'Unpaid')
            ->where('tph.no_surat_permohonan', $no_surat_permohonan)
            ->when(!is_null($isSplit), function ($q) use ($isSplit) {
                $q->where('tph.sp_split', $isSplit);
            })
            ->select([
                'tcpg.order_id',
                'tcpg.cstb_payment_id as payment_id',
                'tph.trx_no',
                'tcpg.payment_amount_ijp as total_amount',
                'tcpg.order_payment_token'
            ])
            ->get();
    }
}
