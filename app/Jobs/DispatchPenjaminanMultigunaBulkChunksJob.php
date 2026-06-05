<?php

namespace App\Jobs;

use App\Models\v2\BulkStgMultigunaModel;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchPenjaminanMultigunaBulkChunksJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const QUERY_CHUNK_SIZE = 500;

    private const JOB_CHUNK_SIZE = 10;

    private const BATCH_ADD_SIZE = 20;

    public int $timeout = 0;

    public int $tries = 1;

    public string $userToken = '';

    public string $authMitraId = '';

    public function __construct(
        public readonly string $bulkNo,
        public readonly string $userName,
        public readonly string $userId,
        public readonly string $mitraId,
        public readonly string $tenantId,
        string $userToken = '',
        string $authMitraId = '',
    ) {
        $this->userToken = $userToken;
        $this->authMitraId = $authMitraId;
        $this->onQueue('bulk-multiguna');
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $batch = $this->batch();
        if ($batch && $batch->totalJobs > 1) {
            Log::warning('Bulk penjaminan multiguna dispatch skipped because chunk jobs already exist.', [
                'bulk_no' => $this->bulkNo,
                'batch_id' => $batch->id,
                'total_jobs' => $batch->totalJobs,
            ]);

            return;
        }

        $query = BulkStgMultigunaModel::query()
            ->where('tenant_id', $this->tenantId)
            ->where('mitra_id', $this->mitraId)
            ->where('bulk_no', $this->bulkNo);

        $totalRecords = (clone $query)->count();
        if ($totalRecords === 0) {
            Log::warning('No staging data found for bulk penjaminan multiguna dispatch.', [
                'bulk_no' => $this->bulkNo,
                'tenant_id' => $this->tenantId,
                'mitra_id' => $this->mitraId,
            ]);

            return;
        }

        BulkStgMultigunaModel::query()
            ->where('tenant_id', $this->tenantId)
            ->where('mitra_id', $this->mitraId)
            ->where('bulk_no', $this->bulkNo)
            ->update([
                'status' => 'processing',
                'updated_at' => Carbon::now('Asia/Jakarta'),
            ]);

        Log::info('Dispatching bulk penjaminan multiguna chunk jobs.', [
            'bulk_no' => $this->bulkNo,
            'tenant_id' => $this->tenantId,
            'mitra_id' => $this->mitraId,
            'records' => $totalRecords,
            'job_chunk_size' => self::JOB_CHUNK_SIZE,
        ]);

        $nomorSuratChunk = [];
        $pendingJobs = [];
        $chunkNumber = 1;
        $totalChunkJobs = 0;

        $records = $query
            ->select('nomor_surat_permohonan')
            ->orderBy('nomor_surat_permohonan')
            ->lazy(self::QUERY_CHUNK_SIZE);

        foreach ($records as $record) {
            if ($this->batch()?->cancelled()) {
                return;
            }

            $nomorSuratChunk[] = (string) $record->nomor_surat_permohonan;

            if (count($nomorSuratChunk) >= self::JOB_CHUNK_SIZE) {
                $pendingJobs[] = $this->makeChunkJob($chunkNumber, $nomorSuratChunk);
                $nomorSuratChunk = [];
                $chunkNumber++;

                $this->flushPendingJobs($pendingJobs, $totalChunkJobs);
            }
        }

        if ($nomorSuratChunk !== []) {
            $pendingJobs[] = $this->makeChunkJob($chunkNumber, $nomorSuratChunk);
        }

        $this->flushPendingJobs($pendingJobs, $totalChunkJobs, true);

        Log::info('Bulk penjaminan multiguna chunk dispatch finished.', [
            'bulk_no' => $this->bulkNo,
            'tenant_id' => $this->tenantId,
            'mitra_id' => $this->mitraId,
            'chunk_jobs' => $totalChunkJobs,
        ]);
    }

    private function flushPendingJobs(array &$pendingJobs, int &$totalChunkJobs, bool $force = false): void
    {
        if ($pendingJobs === []) {
            return;
        }

        if (! $force && count($pendingJobs) < self::BATCH_ADD_SIZE) {
            return;
        }

        $this->batch()?->add($pendingJobs);
        $totalChunkJobs += count($pendingJobs);
        $pendingJobs = [];
    }

    private function makeChunkJob(int $chunkNumber, array $nomorSuratPermohonan): ProcessPenjaminanMultigunaBulkChunkJob
    {
        return new ProcessPenjaminanMultigunaBulkChunkJob(
            $this->bulkNo,
            $chunkNumber,
            $nomorSuratPermohonan,
            $this->userName,
            $this->userId,
            $this->mitraId,
            $this->tenantId,
            $this->userToken,
            $this->authMitraId,
        );
    }
}
