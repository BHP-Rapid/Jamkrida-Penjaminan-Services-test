<?php

namespace App\Support;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;

class HorizonBatchRetryState
{
    public function handleProcessedJob(JobProcessed $event): void
    {
        $payload = $event->job->payload();
        $originalFailedJobId = $payload['retry_of'] ?? null;
        $batchId = $payload['data']['batchId'] ?? null;

        if (! is_string($originalFailedJobId) || $originalFailedJobId === '') {
            return;
        }

        if (! is_string($batchId) || $batchId === '') {
            return;
        }

        $connection = DB::connection(config('queue.batching.database'));
        $table = config('queue.batching.table', 'job_batches');

        $connection->transaction(function () use ($connection, $table, $batchId, $originalFailedJobId) {
            $batch = $connection->table($table)
                ->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                return;
            }

            $failedJobIds = json_decode($batch->failed_job_ids ?: '[]', true);

            if (! is_array($failedJobIds) || ! in_array($originalFailedJobId, $failedJobIds, true)) {
                return;
            }

            $remainingFailedJobIds = array_values(array_diff($failedJobIds, [$originalFailedJobId]));
            $updates = [
                'failed_jobs' => max(0, (int) $batch->failed_jobs - 1),
                'failed_job_ids' => json_encode($remainingFailedJobIds, JSON_THROW_ON_ERROR),
            ];

            if ((int) $batch->pending_jobs <= 0 && $remainingFailedJobIds === [] && $batch->finished_at === null) {
                $updates['finished_at'] = time();
            }

            $connection->table($table)
                ->where('id', $batchId)
                ->update($updates);
        });
    }
}
