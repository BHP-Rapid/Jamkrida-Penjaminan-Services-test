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

    public function getMltAdditionalDocument(string $trxNo)
    {
        $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('multiguna_transaction as mt', 'tph.trx_no', '=', 'mt.trx_no')
            ->join('multiguna_debitur as md', 'md.multiguna_trx_id', '=', 'mt.id_multiguna')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = md.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'md.debitur_name',
                'md.no_sp_detail',
                'md.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;

            return $row;
        });

        return $rows->count() === 1
            ? $rows->first()
            : $rows;
    }

    public function  getSrtbAdditionalDoc(string $trxNo)
    {
        $rows = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('surety_bond_transaction as sbt', 'sbt.trx_no', '=', 'tph.trx_no')
            ->join('penjaminan_flow as pf', function ($join) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NoSuratPermohonan')) = tph.no_surat_permohonan");
            })
            ->where('tph.trx_no', $trxNo)
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'sbt.obligee_name',
                'sbt.principal_name',
                'pf.additional_document',
                'pf.created_at',
            ])
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $rows = $rows->map(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });
        return  $rows->first();
    }

    public function getCstbbAdditionalDocument(string $trxNo)
    {
        $rows = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('custom_bond_transaction as cbt', 'cbt.trx_no', '=', 'tph.trx_no')
            ->join('penjaminan_flow as pf', function ($join) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NoSuratPermohonan')) = tph.no_surat_permohonan");
            })
            ->where('tph.trx_no', $trxNo)
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'cbt.obligee_name',
                'cbt.principal_name',
                'pf.additional_document',
                'pf.created_at',
            ])
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $rows = $rows->map(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });
        return  $rows->first();
    }

    public function getKmkAdditionalDocument(string $trxNo)
    {
        $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kredit_mikro_kecil as mtkmk', 'tph.trx_no', '=', 'mtkmk.trx_no')
            ->join('trx_debitur as td', 'td.kredit_mikro_trx_id', '=', 'mtkmk.id_multiguna_kredit_mikro_kecil')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = md.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'md.debitur_name',
                'md.no_sp_detail',
                'md.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });

        return $rows->count() === 1 ? $rows->first() : $rows;
    }

    public function getKreditUsahaAdditionalDocument(string $trxNo) {
         $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('kredit_usaha_transaction as kut', 'tph.trx_no', '=', 'kut.trx_no')
            ->join('trx_debitur as td', 'td.kredit_usaha_trx_id', '=', 'kut.id_kredit_usaha_transaction')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = td.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'td.nama_nasabah as debitur_name',
                'td.no_sp_detail',
                'td.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });

        return $rows->count() === 1 ? $rows->first() : $rows;
    }

    public function getKreditUsahaRakyatAdditionalDocument(string $trxNo) {
        $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('kur_transaction as kt', 'tph.trx_no', '=', 'kt.trx_no')
            ->join('trx_debitur as td', 'td.kur_trx_id', '=', 'kt.id_kur')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = td.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'td.nama_nasabah as debitur_name',
                'td.no_sp_detail',
                'td.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });

        return $rows->count() === 1 ? $rows->first() : $rows;
    }

    public function getKKPBJAdditionalDocument(string $trxNo) {
         $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kreditkonstruksi as mtk', 'tph.trx_no', '=', 'mtk.trx_no')
            ->join('trx_debitur_construction as tdc', 'tdc.id_multiguna_konstruksi', '=', 'mtk.id_multiguna_konstruksi')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tdc.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'tdc.nama_nasabah as debitur_name',
                'tdc.no_sp_detail',
                'tdc.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });

        return $rows->count() === 1 ? $rows->first() : $rows;
    }

    public function getKPRAdditionalDocument(string $trxNo) {
         $base = PenjaminanTransaction::from('transaction_penjaminan_header as tph')
            ->join('multiguna_trx_kpr as mtk', 'tph.trx_no', '=', 'mtk.trx_no')
            ->join('trx_debitur_kpr as tdk', 'tdk.id_multiguna_kpr', '=', 'mtk.id_multiguna_kpr')
            ->where('tph.trx_no', $trxNo);
        $debiturCount = (clone $base)->count();
        $pfJsonMatch = $debiturCount === 1
            ? "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tph.no_surat_permohonan"
            : "JSON_UNQUOTE(JSON_EXTRACT(pf.additional_document, '$.NomorSpDetail')) = tdk.no_sp_detail";

        $rows = (clone $base)
            ->join('penjaminan_flow as pf', function ($join) use ($pfJsonMatch) {
                $join->on('pf.trx_no', '=', 'tph.trx_no')
                    ->where('pf.trx_status', '=', 'AD')
                    ->whereRaw($pfJsonMatch);
            })
            ->select([
                'tph.trx_no',
                'tph.no_surat_permohonan',
                'tph.trx_status',
                'tdk.nama_nasabah as debitur_name',
                'tdk.no_sp_detail',
                'tdk.no_sp_core_debitur',
                'pf.additional_document',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }
        $rows->transform(function ($row) {
            $row->additional_document = $row->additional_document
                ? json_decode($row->additional_document, true)
                : null;
            return $row;
        });

        return $rows->count() === 1 ? $rows->first() : $rows;
    }
}
