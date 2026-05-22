<?php

namespace Tests\Unit;

use App\Support\HorizonBatchRetryState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HorizonBatchRetryStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    public function test_successful_horizon_retry_clears_original_failed_batch_job_id(): void
    {
        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => 'Dummy Bulk Multiguna batch-1',
            'total_jobs' => 2,
            'pending_jobs' => 0,
            'failed_jobs' => 1,
            'failed_job_ids' => json_encode(['original-failed-job-id'], JSON_THROW_ON_ERROR),
            'options' => null,
            'cancelled_at' => null,
            'created_at' => time(),
            'finished_at' => null,
        ]);

        app(HorizonBatchRetryState::class)->handleProcessedJob(new JobProcessed(
            'redis',
            new class
            {
                public function payload(): array
                {
                    return [
                        'retry_of' => 'original-failed-job-id',
                        'data' => [
                            'batchId' => 'batch-1',
                        ],
                    ];
                }
            },
        ));

        $batch = DB::table('job_batches')->where('id', 'batch-1')->first();

        $this->assertSame(0, (int) $batch->failed_jobs);
        $this->assertSame([], json_decode($batch->failed_job_ids, true));
        $this->assertNotNull($batch->finished_at);
    }

    public function test_non_horizon_retry_jobs_do_not_change_batch_state(): void
    {
        DB::table('job_batches')->insert([
            'id' => 'batch-1',
            'name' => 'Dummy Bulk Multiguna batch-1',
            'total_jobs' => 2,
            'pending_jobs' => 1,
            'failed_jobs' => 1,
            'failed_job_ids' => json_encode(['original-failed-job-id'], JSON_THROW_ON_ERROR),
            'options' => null,
            'cancelled_at' => null,
            'created_at' => time(),
            'finished_at' => null,
        ]);

        app(HorizonBatchRetryState::class)->handleProcessedJob(new JobProcessed(
            'redis',
            new class
            {
                public function payload(): array
                {
                    return [
                        'data' => [
                            'batchId' => 'batch-1',
                        ],
                    ];
                }
            },
        ));

        $batch = DB::table('job_batches')->where('id', 'batch-1')->first();

        $this->assertSame(1, (int) $batch->failed_jobs);
        $this->assertSame(['original-failed-job-id'], json_decode($batch->failed_job_ids, true));
        $this->assertNull($batch->finished_at);
    }
}
