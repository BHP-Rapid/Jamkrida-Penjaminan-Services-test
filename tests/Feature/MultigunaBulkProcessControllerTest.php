<?php

namespace Tests\Feature;

use App\Jobs\DispatchPenjaminanMultigunaBulkChunksJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultigunaBulkProcessControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        Schema::dropIfExists('bulk_stg_penjaminan_multiguna');
        Schema::create('bulk_stg_penjaminan_multiguna', function (Blueprint $table): void {
            $table->string('tenant_id');
            $table->string('mitra_id');
            $table->string('bulk_no');
            $table->string('nomor_surat_permohonan')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bulk_stg_penjaminan_multiguna');

        parent::tearDown();
    }

    public function test_bulk_process_api_marks_staging_queued_and_dispatches_batch(): void
    {
        Bus::fake();

        DB::table('bulk_stg_penjaminan_multiguna')->insert([
            'tenant_id' => 'JDKI01',
            'mitra_id' => 'MDR',
            'bulk_no' => 'BPM202606030001',
            'nomor_surat_permohonan' => 'SP001',
            'status' => 'processed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v2/penjaminan/multiguna/bulk-process', [
            'bulk_no' => 'BPM202606030001',
            'user_id' => 'USR001',
            'user_name' => 'Samuel',
            'tenant_id' => 'JDKI01',
            'mitra_id' => 'MDR',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bulk_no', 'BPM202606030001')
            ->assertJsonPath('data.queue', 'bulk-multiguna')
            ->assertJsonPath('data.total_records', 1)
            ->assertJsonPath('data.already_queued', false);

        $this->assertDatabaseHas('bulk_stg_penjaminan_multiguna', [
            'tenant_id' => 'JDKI01',
            'mitra_id' => 'MDR',
            'bulk_no' => 'BPM202606030001',
            'status' => 'queued',
        ]);

        Bus::assertBatched([
            fn (DispatchPenjaminanMultigunaBulkChunksJob $job): bool => $job->bulkNo === 'BPM202606030001'
                && $job->userId === 'USR001'
                && $job->userName === 'Samuel'
                && $job->tenantId === 'JDKI01'
                && $job->mitraId === 'MDR',
        ]);
    }

    public function test_bulk_process_api_returns_not_found_when_staging_is_missing(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/v2/penjaminan/multiguna/bulk-process', [
            'bulk_no' => 'BPM202606030404',
            'user_id' => 'USR001',
            'user_name' => 'Samuel',
            'tenant_id' => 'JDKI01',
            'mitra_id' => 'MDR',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }
}
