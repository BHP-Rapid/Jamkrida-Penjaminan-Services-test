<?php

namespace App\Jobs;

use App\Helpers\AesHelper;
use App\Models\Institution;
use App\Models\MultigunaDebitur;
use App\Models\MultigunaTransaction;
use App\Models\PenjaminanFlow;
use App\Models\PenjaminanTransaction;
use App\Models\v2\BulkStgMultigunaModel;
use App\Services\PenjaminanMultigunaRabbitPublisher;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class ProcessPenjaminanMultigunaBulkChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const INSERT_CHUNK_SIZE = 20;

    private const PROCESS_COLUMNS = [
        'tenant_id',
        'mitra_id',
        'bulk_no',
        'nomor_surat_permohonan',
        'nomor_pks',
        'bank',
        'bank_cabang',
        'fee_base',
        'teks_penjaminan',
        'tgl_surat_pengajuan',
        'is_split',
        'debitur',
    ];

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(
        public readonly string $bulkNo,
        public readonly int $chunkNumber,
        public readonly array $nomorSuratPermohonan,
        public readonly string $userName,
        public readonly string $userId,
        public readonly string $mitraId,
        public readonly string $tenantId,
    ) {
        $this->onQueue('bulk-multiguna');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $records = BulkStgMultigunaModel::query()
            ->where('tenant_id', $this->tenantId)
            ->where('mitra_id', $this->mitraId)
            ->where('bulk_no', $this->bulkNo)
            ->whereIn('nomor_surat_permohonan', $this->nomorSuratPermohonan)
            ->select(self::PROCESS_COLUMNS)
            ->orderBy('nomor_surat_permohonan')
            ->get();

        foreach ($records as $record) {
            Log::info('Processing bulk penjaminan multiguna record.', [
                'bulk_no' => $this->bulkNo,
                'chunk' => $this->chunkNumber,
                'nomor_surat_permohonan' => $record->nomor_surat_permohonan,
            ]);

            $trxNo = $this->processRecord($record);

            if ($trxNo !== null) {
                app(PenjaminanMultigunaRabbitPublisher::class)->dispatchRegistration($trxNo, $this->bulkNo);
            }
        }

        Log::info('Processed bulk penjaminan multiguna chunk.', [
            'bulk_no' => $this->bulkNo,
            'chunk' => $this->chunkNumber,
            'records' => $records->count(),
            'tenant_id' => $this->tenantId,
            'mitra_id' => $this->mitraId,
        ]);
    }

    private function processRecord(BulkStgMultigunaModel $data): ?string
    {
        return DB::transaction(function () use ($data): ?string {
            $existing = PenjaminanTransaction::query()
                ->where('mitra_id', $this->mitraId)
                ->where('product', 'mlt')
                ->where('no_surat_permohonan', $data->nomor_surat_permohonan)
                ->select('trx_no')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                Log::warning('Bulk penjaminan multiguna record skipped because transaction already exists.', [
                    'bulk_no' => $this->bulkNo,
                    'trx_no' => $existing->trx_no,
                    'nomor_surat_permohonan' => $data->nomor_surat_permohonan,
                    'mitra_id' => $this->mitraId,
                ]);

                return $existing->trx_no;
            }

            $currentYear = date('Y');
            $currentMonth = date('m');
            $lastTrx = PenjaminanTransaction::lockForUpdate()
                ->where('trx_no', 'like', 'PNJ-'.$currentYear.'-'.$currentMonth.'%')
                ->orderBy('trx_no', 'desc')
                ->value('trx_no');

            $nextSeq = $lastTrx ? intval(substr($lastTrx, -4)) + 1 : 1;
            $trxNo = 'PNJ-'.$currentYear.'-'.$currentMonth.'-'.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
            $permohonanDate = $this->dateValue($data->tgl_surat_pengajuan, true);
            $nowJakarta = Carbon::now('Asia/Jakarta');
            $debiturList = $this->decodeDebiturList($data->debitur);

            PenjaminanTransaction::create([
                'trx_no' => $trxNo,
                'sp_split' => $data->is_split,
                'no_surat_permohonan' => $data->nomor_surat_permohonan,
                'tanggal_surat_permohonan' => $permohonanDate,
                'trx_status' => 'NA',
                'status_sync_creatio' => 0,
                'created_by_name' => $this->userName,
                'created_at' => $nowJakarta,
                'created_by_id' => $this->userId,
                'mitra_id' => $this->mitraId,
                'product' => 'mlt',
                'no_rek' => '012312',
            ]);

            $multiguna = MultigunaTransaction::create([
                'trx_no' => $trxNo,
                'jenis_product_description' => 'Multiguna',
                'pks_number' => $data->nomor_pks,
                'fee_base_number' => $data->fee_base,
                'fee_base_percentage' => $data->fee_base,
                'bank_name' => $data->bank_cabang,
                'bank_code' => $data->bank,
                'text_certified' => $data->teks_penjaminan,
                'created_at' => $nowJakarta,
            ]);

            $multigunaId = $multiguna->getKey();
            $mitraId = strtoupper($this->mitraId);
            $prefix = $mitraId.$currentYear;
            $lastLoan = MultigunaDebitur::lockForUpdate()
                ->where('loan_number', 'like', $prefix.'%')
                ->orderBy('loan_number', 'desc')
                ->value('loan_number');
            $startSeq = $lastLoan ? ((int) substr($lastLoan, -4)) + 1 : 1;

            $institutionMap = [];
            $key = base64_decode((string) config('services.secure.key'));
            $hashKey = (string) config('services.secure.hash_key');
            $enc = fn (mixed $value): ?string => $this->encryptValue($value, $key);

            $rowsInstitutions = collect($debiturList)
                ->filter(fn (mixed $debitur): bool => is_array($debitur))
                ->map(function (array $debitur) use ($nowJakarta, &$institutionMap, $enc, $hashKey): array {
                    $nikRaw = $this->nikValue($debitur);
                    $instId = (string) Str::uuid();

                    if ($nikRaw !== null) {
                        $institutionMap[$nikRaw] = $instId;
                    }

                    return [
                        'category' => 'P',
                        'mitra_id' => strtoupper($this->mitraId),
                        'tenant_id' => $this->tenantId,
                        'id_issued_location' => '-',
                        'id_add_issued_location' => '-',
                        'id_add_type' => '-',
                        'created_by' => $this->userId,
                        'full_name' => $debitur['namaMakfulAnhu'] ?? $debitur['full_name'] ?? null,
                        'home_province' => $debitur['provinsiMakfulAnhu'] ?? null,
                        'home_city' => $debitur['kotaKabupatenMakfulAnhu'] ?? 0,
                        'home_district' => $debitur['kecamatanMakfulAnhu'] ?? null,
                        'home_sub_district' => $debitur['kelurahanMakfulAnhu'] ?? null,
                        'home_zipcode' => $debitur['kodePosMakfulAnhu'] ?? null,
                        'birth_place' => $debitur['tempatLahir'] ?? null,
                        'birth_date' => $enc($debitur['tanggalLahir'] ?? null),
                        'gender' => $debitur['jenisKelamin'] ?? null,
                        'id_type' => $debitur['jenisIdentitas'] ?? null,
                        'id_number' => $enc($nikRaw),
                        'id_number_hash' => $nikRaw !== null ? hash_hmac('sha256', $nikRaw, $hashKey) : null,
                        'job_id' => $debitur['kategoriPekerjaan'] ?? null,
                        'job_level' => $debitur['jabatan'] ?? null,
                        'job_employer_name' => $debitur['namaPemberiKerja'] ?? null,
                        'job_start_date' => $this->dateValue($debitur['tanggalMulaiBekerja'] ?? null),
                        'job_industry_type' => $debitur['kodeIndustriInternalPemberiKerja'] ?? null,
                        'current_salary_amount' => $enc($debitur['current_salary_amount'] ?? null),
                        'phone_1' => $enc($debitur['nomorTelepon'] ?? $debitur['phone_1'] ?? null),
                        'email_1' => $enc($debitur['email'] ?? $debitur['email_1'] ?? null),
                        'tax_id' => $enc($debitur['npwpGiro'] ?? $debitur['npwp'] ?? null),
                        'current_salary_currency' => $debitur['kodeValutaIdrUsd'] ?? $debitur['current_salary_currency'] ?? null,
                        'tax_type' => 'npwp',
                        'institution_id' => $instId,
                        'created_at' => $nowJakarta,
                    ];
                })
                ->values()
                ->all();

            $this->insertInChunks(Institution::class, $rowsInstitutions);

            $countDebitur = count($debiturList);
            $debiturs = collect($debiturList)
                ->filter(fn (mixed $debitur): bool => is_array($debitur))
                ->map(function (array $debitur, int $idx) use ($nowJakarta, $multigunaId, $prefix, $startSeq, $institutionMap, $enc, $data, $countDebitur): array {
                    $spSequence = $idx + 1;
                    $baseSp = $data->nomor_surat_permohonan;
                    $seq = $startSeq + $idx;
                    $loanNumber = $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                    $nik = $this->nikValue($debitur);

                    return [
                        'multiguna_trx_id' => $multigunaId,
                        'debitur_name' => $debitur['namaMakfulAnhu'] ?? null,
                        'debitur_address' => $this->debiturAddress($debitur),
                        'no_sp_detail' => $countDebitur > 1 ? $baseSp.'-'.$spSequence : null,
                        'penggunaan_pembiayaan' => $debitur['penggunaanPembiayaan'] ?? 0,
                        'status_debitur' => $debitur['status_debitur'] ?? 'Approved',
                        'ijk' => $this->decimalValue($debitur['ijk'] ?? null, null),
                        'nik' => $enc($nik),
                        'jenis_agunan' => $debitur['jenisAgunan'] ?? $this->decimalValue($debitur['nilaiKafalah'] ?? null, null),
                        'nilai_agunan' => $this->decimalValue($debitur['nilaiAgunan'] ?? null, null),
                        'nilai_kafalah' => $this->decimalValue($debitur['nilaiKafalah'] ?? null, null),
                        'plafond_pembiayaan' => $this->decimalValue($debitur['plafondPembiayaan'] ?? null, null),
                        'tanggal_realisasi' => $this->dateValue($debitur['tanggalRealisasi'] ?? null),
                        'tanggal_jatuh_tempo' => $this->dateValue($debitur['tanggalJatuhTempo'] ?? null),
                        'jenis_penjaminan' => $debitur['jenisPenjaminan'] ?? null,
                        'jenis_makful_anhu' => $debitur['jenisMakfulAnhu'] ?? null,
                        'jw_bulan' => (int) ($debitur['jwBulan'] ?? 0),
                        'loan_number' => $loanNumber,
                        'margin' => $this->decimalValue($debitur['marginBagiHasilUjrahThn'] ?? null, 0),
                        'tenaga_kerja' => $debitur['jenisMakfulAnhu'] ?? null,
                        'institution_id' => $nik !== null ? ($institutionMap[$nik] ?? null) : null,
                        'created_at' => $nowJakarta,
                        'plafond_max_debitur' => $this->decimalValue($debitur['MaksimalNilaiPlafond'] ?? null, 0),
                    ];
                })
                ->values()
                ->all();

            $this->insertInChunks(MultigunaDebitur::class, $debiturs);

            PenjaminanFlow::create([
                'trx_no' => $trxNo,
                'trx_status' => 'NA',
                'created_at' => $nowJakarta,
                'created_by_id' => $this->userId,
                'created_by_name' => $this->userName,
                'updated_at' => null,
            ]);

            return $trxNo;
        }, 3);
    }

    private function decodeDebiturList(mixed $debitur): array
    {
        if (is_string($debitur)) {
            $decoded = json_decode($debitur, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('Data debitur bulk tidak valid untuk bulk '.$this->bulkNo.'.');
            }

            return $decoded;
        }

        if (is_array($debitur)) {
            return $debitur;
        }

        return [];
    }

    private function insertInChunks(string $modelClass, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            $modelClass::insert($chunk);
        }
    }

    private function dateValue(mixed $value, bool $required = false): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException('Tanggal wajib pada bulk '.$this->bulkNo.' tidak boleh kosong.');
            }

            return null;
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value, 'Asia/Jakarta');

                if ($date instanceof Carbon) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                //
            }
        }

        try {
            return Carbon::parse($value, 'Asia/Jakarta')->format('Y-m-d');
        } catch (Throwable $exception) {
            if ($required) {
                throw new InvalidArgumentException("Format tanggal {$value} tidak valid.", previous: $exception);
            }

            return null;
        }
    }

    private function encryptValue(mixed $value, string $key): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? AesHelper::encrypt($value, $key) : null;
    }

    private function nikValue(array $debitur): ?string
    {
        $nik = $debitur['nik'] ?? $debitur['NIK'] ?? $debitur['id_number'] ?? null;
        $nik = trim((string) $nik);

        return $nik !== '' ? $nik : null;
    }

    private function debiturAddress(array $debitur): ?string
    {
        $parts = array_filter([
            $debitur['provinsiMakfulAnhu'] ?? null,
            $debitur['kotaKabupatenMakfulAnhu'] ?? null,
            $debitur['kecamatanMakfulAnhu'] ?? null,
            $debitur['kelurahanMakfulAnhu'] ?? null,
        ], fn (mixed $value): bool => trim((string) $value) !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function decimalValue(mixed $value, float|int|null $default = 0): float|int|null
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return $default;
            }

            $value = str_replace(' ', '', $value);
            if (str_contains($value, ',') && str_contains($value, '.')) {
                if (strrpos($value, ',') > strrpos($value, '.')) {
                    $value = str_replace('.', '', $value);
                    $value = str_replace(',', '.', $value);
                } else {
                    $value = str_replace(',', '', $value);
                }
            } elseif (str_contains($value, ',')) {
                $value = str_replace(',', '.', $value);
            }
        }

        return is_numeric($value) ? (float) $value : $default;
    }
}
