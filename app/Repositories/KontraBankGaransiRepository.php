<?php

namespace App\Repositories;

use App\Models\KBGTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanLampiranDtl;
use App\Models\PenjaminanTransaction;
use App\Models\TenantMitra;
use App\Models\v2\KBGTenorSchedule;
use App\Models\v2\KontraBankGaransiInvoiceHeader;
use App\Models\v2\KontraBankGaransiPaymentGateway;
use Carbon\Carbon;
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

    public function getPersonalInstitution(string $id_institution)
    {
        return DB::table('institution')
            ->where('id', $id_institution)
            ->select(
                'id',
                'institution_id',
                'tenant_id',
                'mitra_id',
                'full_name',
                'home_address',
                'home_province',
                'home_city',
                'home_district',
                'home_sub_district',
                'home_zipcode',
                'birth_place',
                'birth_date',
                'gender',
                'mother_name',
                'id_type',
                'id_number',
                'id_number_hash',
                'id_issued_location',
                'id_add_type',
                'id_add_number',
                'id_add_issued_location',
                'tax_type',
                'tax_id',
                'job_id',
                'job_level',
                'job_employer_name',
                'job_start_date',
                'job_industry_type',
                'current_salary_amount',
                'current_salary_currency',
                'other_income_source',
                'other_income_type',
                'other_income_currency',
                'other_income_amount',
                'phone_1',
                'email_1'
            )->first();
    }

    public function getTrxKbgId(string $trx_no)
    {
        return KBGTransaction::where('trx_no', $trx_no)
            ->select('id_trx_product')->first();
    }

    public function getHeaderKbgStatus(string $trx_no)
    {
        return PenjaminanTransaction::where('trx_no', $trx_no)
            ->select('trx_no', 'trx_status')->first();
    }

    public function getTrxKbgDetail(string $trx_no)
    {
        return PenjaminanTransaction::join(
            'kbg_transaction as kbg',
            'transaction_penjaminan_header.trx_no',
            '=',
            'kbg.trx_no'
        )->where('transaction_penjaminan_header.trx_no', $trx_no)
        ->select(
            'transaction_penjaminan_header.trx_no',
            'transaction_penjaminan_header.trx_status',
            'transaction_penjaminan_header.no_surat_permohonan',
            'transaction_penjaminan_header.tanggal_surat_permohonan',
            'transaction_penjaminan_header.sp_split',
            'transaction_penjaminan_header.product',
            'kbg.jenis_garansi',
            'kbg.jenis_garansi_description',
            'kbg.jenis_persyaratan',
            'kbg.skema_penalty',
            'kbg.sektor',
            'kbg.id_institution',
            'kbg.principal_name',
            'kbg.obligee_name',
            'kbg.is_bast',
            'kbg.no_surat_bast',
            'kbg.bast_date',
            'kbg.bank_code',
            'kbg.bank_name',
            'kbg.project_name',
            'kbg.project_amount',
            'kbg.amount_garansi',
            'kbg.garansi_percentage',
            'kbg.start_period_date',
            'kbg.end_period_date',
            'kbg.total_day',
            'kbg.province',
            'kbg.tgl_surat_perjanjian',
            'kbg.no_surat_perjanjian',
            'kbg.jenis_surat_perjanjian',
            'kbg.tarif_percentage',
            'kbg.agunan_amount',
            'transaction_penjaminan_header.created_at',
            'transaction_penjaminan_header.updated_at'
        )->first();
    }

    public function getPenjaminanLampiranDetail(string $trx_no, string $mitra_alias)
    {
        $lampiranMax = PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select(
                'trx_no',
                'lampiran_id',
                DB::raw(
                    'MAX(version) as latest_version',
                ))->groupBy('trx_no', 'lampiran_id');
        $lampiranLatest = PenjaminanLampiranDtl::joinSub(
            $lampiranMax,
            'latest',
            function ($join) {
                $join->on('penjaminan_lampiran_dtl.trx_no', '=', 'latest.trx_no')
                    ->on('penjaminan_lampiran_dtl.lampiran_id', '=', 'latest.lampiran_id')
                    ->on('penjaminan_lampiran_dtl.version', '=', 'latest.latest_version');
            })->select(
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
                // mapping value columns
                'c.value',
                'c.label',
                'c.option2',
                // lampiran dtl columns
                'lt.lampiran_id',
                'lt.file_name',
                'lt.file_info',
                'lt.is_additional',
                'lt.status_doc',
                'lt.mime_type',
                'lt.version'
            )
            ->where('a.module', 'PENJAMINAN_SETTINGS')
            ->where('b.product_id', 'kbg')
            ->where('a.mitra_id', $mitra_alias)
            ->where('b.is_mandatory', 1)
            ->where('c.key', 'lampiran')
            ->whereNotNull('b.lampiran')
            ->orderBy('c.value', 'asc')
            ->get()->toArray();
    }

    public function getPenjaminanLampiranLatestVersionList(string $trx_no)
    {
        return PenjaminanLampiranDtl::where('trx_no', $trx_no)
            ->select('lampiran_id', DB::raw('MAX(version) as version'))
            ->groupBy('lampiran_id')
            ->get();
    }

    public function getSuratPermohonanKbg(string $trx_no)
    {
        return PenjaminanTransaction::where('trx_no', $trx_no)
            ->select('no_surat_permohonan')->first();
    }

    public function getTenorDataKbg(string $trx_no, string $invoice_no)
    {
        return KBGTenorSchedule::query()
            ->from('kbg_transaction as kbg')
            ->join('kbg_tenor_schedule as kbts',
                'kbg.id_trx_product', '=', 'kbts.id_trx_product')
            ->select([
                'kbts.kbg_schedule_id',
                'kbg.id_trx_product',
                'kbg.trx_no',
                'kbts.tenor_sequence',
                'kbts.invoice_number',
                'kbts.amount',
                'kbts.status'
            ])
            ->where('kbts.status', 'Pending')
            ->where('kbts.invoice_number', $invoice_no)
            ->where('kbg.trx_no', $trx_no)
            ->get();
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

    public function insertPenjaminanKbgFlow(string $trx_no, string $status_code, object $user, $status_approval = null)
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

    public function insertInvoiceHeaderManual(array $tenor_data, string $status)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        return KontraBankGaransiInvoiceHeader::create([
            'kbg_schedule_id' => $tenor_data['kbg_schedule_id'],
            'invoice_scope' => $tenor_data['invoice_scope'],
            'total_amount' => $tenor_data['amount'],
            'status' => $status,
            'is_manual' => 1,
            'created_at' => $time_now_jakarta,
            'updated_at' => $time_now_jakarta
        ]);
    }

    public function insertPaymentGatewayManual(string $invoice_id, string $order_id, float $amount)
    {
        $time_now_jakarta = Carbon::now('Asia/Jakarta');
        KontraBankGaransiPaymentGateway::create([
            'kbg_invoice_id' => $invoice_id,
            'payment_amount_ijp' => $amount,
            'status' => 'Paid',
            'order_id' => $order_id,
            'created_at' => $time_now_jakarta,
            'updated_at' => $time_now_jakarta
        ]);
    }

    public function updateHeaderKbgDraft(string $trx_no, array $data)
    {
        PenjaminanTransaction::where('trx_no', $trx_no)
            ->update($data);
    }

    public function updateTrxKbg(string $trx_no, array $data)
    {
        KBGTransaction::where('trx_no', $trx_no)
            ->update($data);
    }

    public function updateTenorDataByScheduleId(int $schedule_id, array $data)
    {
        KBGTenorSchedule::where('kbg_schedule_id', $schedule_id)
            ->update($data);
    }
}
