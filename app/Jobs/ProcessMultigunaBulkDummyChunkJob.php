<?php

namespace App\Jobs;

use DateTimeImmutable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMultigunaBulkDummyChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public readonly string $bulkId,
        public readonly int $chunkNumber,
        public readonly array $rows,
    ) {
        $this->onQueue('bulk-multiguna');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $startedAt = microtime(true);
        $processed = 0;
        $invalid = 0;
        $totalPlafond = 0.0;
        $samples = [];
        $lastChecksum = null;

        foreach ($this->rows as $row) {
            $dummyPayload = $this->buildDummyPayload($row);

            if (! $this->isValidDummyPayload($dummyPayload)) {
                $invalid++;
            }

            $totalPlafond += $dummyPayload['plafond_pembiayaan'];
            $lastChecksum = $dummyPayload['checksum'];
            $processed++;

            if (count($samples) < 3) {
                $samples[] = [
                    'line' => $dummyPayload['line'],
                    'no_surat_permohonan' => $dummyPayload['no_surat_permohonan'],
                    'nama_makful_anhu' => $dummyPayload['nama_makful_anhu'],
                    'nik' => $dummyPayload['nik'],
                    'plafond_pembiayaan' => $dummyPayload['plafond_pembiayaan'],
                ];
            }
        }

        Log::info('Dummy bulk multiguna chunk processed', [
            'bulk_id' => $this->bulkId,
            'chunk' => $this->chunkNumber,
            'processed' => $processed,
            'invalid' => $invalid,
            'total_plafond' => $totalPlafond,
            'sample_rows' => $samples,
            'last_checksum' => $lastChecksum,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'database_write' => false,
        ]);
    }

    private function buildDummyPayload(array $row): array
    {
        $data = $row['data'] ?? [];

        $payload = [
            'line' => $row['line'] ?? null,
            'no_surat_permohonan' => $this->nullableString($data['No surat permohonan'] ?? null),
            'jenis_product' => $this->nullableString($data['Jenis product'] ?? null),
            'bank' => $this->nullableString($data['bank'] ?? null),
            'bank_cabang' => $this->nullableString($data['Bank cabang'] ?? null),
            'nomor_pks' => $this->nullableString($data['Nomor pks'] ?? null),
            'tgl_surat_pengajuan' => $this->normalizeDate($data['Tgl surat pengajuan'] ?? null),
            'pembayaran_split_per_debitur' => $this->normalizeYesNo($data['Pembayaran Split Per Debitur'] ?? null),
            'nama_makful_anhu' => $this->nullableString($data['Nama Makful Anhu'] ?? null),
            'nomor_telepon' => $this->nullableString($data['Nomor Telepon'] ?? null),
            'email' => $this->nullableString($data['Email'] ?? null),
            'nik' => $this->nullableString($data['NIK'] ?? null),
            'plafond_pembiayaan' => $this->toFloat($data['Plafond Pembiayaan'] ?? null),
            'margin_tahunan' => $this->toFloat($data['Margin/Bagi Hasil/Ujrah (Thn)'] ?? null),
            'jw_bulan' => (int) $this->toFloat($data['JW Bulan'] ?? null),
            'tanggal_realisasi' => $this->normalizeDate($data['Tanggal Realisasi (yyyy-mm-dd)'] ?? null),
            'tanggal_jatuh_tempo' => $this->normalizeDate($data['Tanggal Jatuh Tempo (yyyy-mm-dd)'] ?? null),
            'nilai_agunan' => $this->toFloat($data['Nilai Agunan'] ?? null),
            'raw_column_count' => count($data),
        ];

        $payload['checksum'] = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $payload;
    }

    private function isValidDummyPayload(array $payload): bool
    {
        return $payload['no_surat_permohonan'] !== null
            && $payload['nama_makful_anhu'] !== null
            && $payload['nik'] !== null
            && $payload['plafond_pembiayaan'] > 0;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeYesNo(mixed $value): ?bool
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'ya', 'yes', 'y', 'true', '1' => true,
            'tidak', 'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);

            if ($date instanceof DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function toFloat(mixed $value): float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0.0;
        }

        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
