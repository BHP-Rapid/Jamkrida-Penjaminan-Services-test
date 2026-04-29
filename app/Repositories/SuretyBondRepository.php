<?php

namespace App\Repositories;

use App\Models\Institution;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\SuretyBondTransaction;
use Illuminate\Support\Facades\DB;

class SuretyBondRepository
{
    public function getDetailByTrxNo(string $trxNo)
    {
        return PenjaminanTransaction::join(
            'surety_bond_transaction as sbt',
            'transaction_penjaminan_header.trx_no',
            '=',
            'sbt.trx_no'
        )
            ->where('transaction_penjaminan_header.trx_no', $trxNo)
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
    }


    public function getInstitution(int $idInstitution)
    {
        return Institution::query()
            ->from('institution as institution')
            ->join('surety_bond_transaction as b', 'institution.id', '=', 'b.id_institution')
            ->where('b.id_institution', $idInstitution)
            ->select('b.*', 'institution.*')
            ->first();
    }

    public function getHistory(string $trx_no)
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


    public function getLampiran(string $trx_no)
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
            ->get();
    }

    public function getPendingData(string $trx_no, int $isSplit)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('surety_bond_transaction as sbt', 'tph.trx_no', '=', 'sbt.trx_no')
            ->join('institution as inst', 'sbt.id_institution', '=', 'inst.id')
            ->join('suretybond_tenor_schedule as srbs', 'sbt.id_trx_product', '=', 'srbs.id_trx_product')
            ->where('tph.trx_no', $trx_no)
            ->where(function ($query) {
                $query->where('srbs.status', 'Pending')
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
            ])
            ->get();
    }

    public function getUnpaidData(string $trx_no, $isSplit)
    {
        return PenjaminanTransaction::query()
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
    }

    public function getHeaderWithDetail(string $trxNo)
    {
        return PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->join('surety_bond_transaction as sbt', 'tph.trx_no', '=', 'sbt.trx_no')
            ->where('tph.trx_no', $trxNo)
            ->select([
                'tph.trx_no',
                'tph.trx_status',
                'sbt.id_trx_product'
            ])
            ->first();
    }

    public function updateHeader(string $trxNo, array $data)
    {
        return PenjaminanTransaction::where('trx_no', $trxNo)->update($data);
    }

    public function updateDetail(string $trxNo, array $data)
    {
        return SuretyBondTransaction::where('trx_no', $trxNo)->update($data);
    }

    public function insertLampiran(array $data)
    {
        return DB::table('penjaminan_lampiran_dtl')->insert($data);
    }

    public function getLatestLampiranVersion($trxNo)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trxNo)
            ->select('lampiran_id', DB::raw('MAX(version) as version'))
            ->groupBy('lampiran_id')
            ->pluck('version', 'lampiran_id')
            ->toArray();
    }

    public function insertFlow(array $data)
    {
        return PenjaminanFlow::create($data);
    }

    public function createHeader(array $data)
    {
        return PenjaminanTransaction::create($data);
    }

    public function createDetail(array $data)
{
    return SuretyBondTransaction::create($data);
}
}
