<?php

namespace App\Repositories;

use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use Illuminate\Support\Facades\DB;

class PenjaminanTransactionRepository
{
    //
    public function getTransactionList(array $params)
    {
        $mitraAlias = null;
        if (!empty($params['mitra_id'])) {
            $mitraAlias = TenantMitra::where('mitra_id', $params['mitra_id'])
                ->value('alias');
        }
        $query = PenjaminanTransaction::query()
            ->from('transaction_penjaminan_header as tph')
            ->leftJoin('surety_bond_transaction as sbt', 'sbt.trx_no', '=', 'tph.trx_no')
            ->leftJoin('custom_bond_transaction as cbt', 'cbt.trx_no', '=', 'tph.trx_no')
            ->leftJoin('multiguna_transaction as mt', 'mt.trx_no', '=', 'tph.trx_no')
            ->leftJoin('multiguna_debitur as md', 'md.multiguna_trx_id', '=', 'mt.id_multiguna')
            ->leftJoin('mapping_value as mv1', 'mv1.value', '=', 'tph.product')
            ->leftJoin('mapping_value as mv2', function ($join) {
                $join->on('mv2.value', '=', 'tph.trx_status')
                    ->where('mv2.key', '=', 'pnj_sts');
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.status_sync_creatio',
                'tph.tanggal_surat_permohonan',
                'tph.product',
                'tph.sp_split',
                'mv1.label as product_description',
                'mv2.label as trx_status',
                DB::raw('MAX(CASE 
                    WHEN tph.product = "mlt" AND md.no_sp_core_debitur IS NOT NULL THEN TRUE
                    WHEN tph.product = "srtb" AND sbt.sp_polis IS NOT NULL THEN TRUE
                    WHEN tph.product = "cstb" AND cbt.sp_polis IS NOT NULL THEN TRUE
                    ELSE FALSE
                END) as is_downloaded'),
            ])
            ->groupBy(
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.status_sync_creatio',
                'tph.tanggal_surat_permohonan',
                'tph.product',
                'tph.sp_split',
                'mv1.label',
                'mv2.label',
            );

        // FILTER MITRA
        if (!is_null($mitraAlias)) {
            $query->where('tph.mitra_id', $mitraAlias);
        }

        // FILTER DINAMIS
        if (!empty($params['filter'])) {
            foreach ($params['filter'] as $filter) {
                if (!isset($filter['id'], $filter['value'])) continue;

                $field = $filter['id'];
                $value = $filter['value'];

                switch ($field) {
                    case 'trx_no':
                        $query->where('tph.trx_no', 'like', "%{$value}%");
                        break;

                    case 'no_surat_permohonan':
                        $query->where('tph.no_surat_permohonan', 'like', "%{$value}%");
                        break;

                    case 'product':
                        $query->where('tph.product', 'like', "%{$value}%");
                        break;

                    case 'trx_status':
                        $query->where('tph.trx_status', 'like', "%{$value}%");
                        break;

                    case 'created_at':
                        if (is_array($value) && count($value) === 2) {
                            $query->whereBetween('tph.tanggal_surat_permohonan', [
                                min($value),
                                max($value)
                            ]);
                        }
                        break;
                }
            }
        }

        // SORTING
        $sortable = [
            'trx_no' => 'tph.trx_no',
            'created_at' => 'tph.created_at',
        ];

        if (!empty($params['sort_column']) && !empty($params['sort'])) {
            if (isset($sortable[$params['sort_column']])) {
                $query->orderBy($sortable[$params['sort_column']], $params['sort']);
            }
        } else {
            $query->orderBy('tph.created_at', 'desc');
        }

        // PAGINATION
        $perPage = (int)($params['show_page'] ?? 10);

        return $query->paginate($perPage);
    }


    public function findValidAdditionalDocTransaction(string $penjaminanNo, string $noSuratPermohonan)
    {
        return PenjaminanTransaction::query()
            ->where('trx_no', $penjaminanNo)
            ->where('no_surat_permohonan', $noSuratPermohonan)
            ->where('trx_status', 'AD')
            ->first();
    }

    public function getMultigunaPenjaminanFlow(string $penjaminanNo, string $pfJsonMatch, ?string $noSpDetail = null)
    {
        $pfJsonMatch = is_null($noSpDetail)
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = md.no_sp_detail";
        $query = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
            ->join('multiguna_debitur as md', 'md.multiguna_trx_id', '=', 'mt.id_multiguna')
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->where('tph.trx_no', $penjaminanNo)
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'md.debitur_name',
                'md.no_sp_detail as nomor_permohonan',
                'md.no_sp_core_debitur',
                'pf.additional_document',
                'pf.created_at',
            ])
            ->orderByDesc('pf.created_at');

        if (!is_null($noSpDetail)) {
            $query->where('md.no_sp_detail', $noSpDetail);
        }

        return $query->first();
    }
}
