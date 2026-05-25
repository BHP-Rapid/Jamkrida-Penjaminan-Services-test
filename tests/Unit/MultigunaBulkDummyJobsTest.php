<?php

namespace Tests\Unit;

use App\Jobs\DispatchMultigunaBulkDummyChunksJob;
use App\Jobs\ProcessMultigunaBulkDummyChunkJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionMethod;
use Tests\TestCase;

class MultigunaBulkDummyJobsTest extends TestCase
{
    public function test_process_chunk_builds_dummy_summary_without_database_write(): void
    {
        Storage::fake('local');

        $chunkPath = 'bulk-dummy/multiguna/chunks/bulk-1/chunk-00001.json';
        Storage::disk('local')->put($chunkPath, json_encode([
            [
                'line' => 2,
                'data' => [
                    'No surat permohonan' => 'KMK202604220',
                    'Jenis product' => 'Multiguna',
                    'bank' => 'mandiri',
                    'Nama Makful Anhu' => 'Ahmad Fauzi',
                    'NIK' => '4332181960013389',
                    'Plafond Pembiayaan' => '10000000',
                    'Pembayaran Split Per Debitur' => 'Ya',
                    'Tanggal Realisasi (yyyy-mm-dd)' => '22/04/2026',
                ],
            ],
            [
                'line' => 3,
                'data' => [
                    'No surat permohonan' => '',
                    'Nama Makful Anhu' => '',
                    'NIK' => '',
                    'Plafond Pembiayaan' => '0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        Log::shouldReceive('info')
            ->once()
            ->with('Dummy bulk multiguna chunk processed', Mockery::on(function (array $context) {
                return $context['bulk_id'] === 'bulk-1'
                    && $context['chunk'] === 1
                    && $context['processed'] === 2
                    && $context['invalid'] === 1
                    && $context['total_plafond'] === 10000000.0
                    && $context['database_write'] === false;
            }));

        $job = new ProcessMultigunaBulkDummyChunkJob('bulk-1', 1, $chunkPath, 'local');

        $job->handle();

        Storage::disk('local')->assertMissing($chunkPath);
    }

    public function test_process_chunk_job_payload_keeps_rows_out_of_horizon_metadata(): void
    {
        $job = new ProcessMultigunaBulkDummyChunkJob(
            'bulk-1',
            1,
            'bulk-dummy/multiguna/chunks/bulk-1/chunk-00001.json',
            'local',
        );

        $serialized = serialize($job);

        $this->assertStringContainsString('chunk-00001.json', $serialized);
        $this->assertStringNotContainsString('Ahmad Fauzi', $serialized);
        $this->assertLessThan(2000, strlen($serialized));
    }

    public function test_dispatch_job_reads_csv_rows_with_detected_delimiter(): void
    {
        $path = $this->temporaryFile('bulk.csv');
        file_put_contents(
            $path,
            "No surat permohonan;Nama Makful Anhu;NIK;Plafond Pembiayaan\n".
            "KMK202604220;Ahmad Fauzi;4332181960013389;41867825\n".
            "KMK202604221;Maya Indah;8637940265423511;49416129\n",
        );

        $rows = $this->readRows($path);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame('Ahmad Fauzi', $rows[0]['data']['Nama Makful Anhu']);
        $this->assertSame('49416129', $rows[1]['data']['Plafond Pembiayaan']);
    }

    public function test_dispatch_job_streams_file_from_storage(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'bulk.csv',
            "No surat permohonan;Nama Makful Anhu;NIK;Plafond Pembiayaan\n".
            "KMK202604220;Ahmad Fauzi;4332181960013389;41867825\n",
        );

        Log::shouldReceive('info')
            ->twice()
            ->withAnyArgs();

        (new DispatchMultigunaBulkDummyChunksJob(
            'bulk-1',
            'bulk.csv',
            'local',
            'bulk.csv',
        ))->handle();

        $chunkPath = 'bulk-dummy/multiguna/chunks/bulk-1/chunk-00001.json';
        Storage::disk('local')->assertExists($chunkPath);

        $rows = json_decode(Storage::disk('local')->get($chunkPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Ahmad Fauzi', $rows[0]['data']['Nama Makful Anhu']);
    }

    public function test_dispatch_job_reads_xlsx_rows_in_ranges(): void
    {
        $path = $this->temporaryFile('bulk.xlsx');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['No surat permohonan', 'Nama Makful Anhu', 'NIK', 'Plafond Pembiayaan'],
            ['KMK202604220', 'Ahmad Fauzi', '4332181960013389', '41867825'],
            ['KMK202604221', 'Maya Indah', '8637940265423511', '49416129'],
        ]);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $rows = $this->readRows($path);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame('Maya Indah', $rows[1]['data']['Nama Makful Anhu']);
    }

    public function test_dispatch_job_skips_when_batch_already_has_chunks(): void
    {
        Storage::fake('local');

        Storage::disk('local')->put(
            'bulk.csv',
            "No surat permohonan;Nama Makful Anhu;NIK;Plafond Pembiayaan\n".
            "KMK202604220;Ahmad Fauzi;4332181960013389;41867825\n",
        );

        // Simulate a batch that already has chunk jobs (totalJobs > 1).
        $fakeBatch = new \Illuminate\Bus\Batch(
            app(\Illuminate\Contracts\Queue\Factory::class),
            app(\Illuminate\Bus\BatchRepository::class),
            'fake-batch-id',
            'Dummy Bulk Test',
            2,      // totalJobs — more than 1 means chunks exist
            0,      // pendingJobs
            0,      // failedJobs
            [],     // failedJobIds
            [],     // options
            \Carbon\CarbonImmutable::now(),
            null,
            null,
        );

        Log::shouldReceive('warning')
            ->once()
            ->with(Mockery::pattern('/chunks already dispatched/'), Mockery::type('array'));

        $job = new DispatchMultigunaBulkDummyChunksJob('bulk-1', 'bulk.csv', 'local', 'bulk.csv');
        $job->withBatchId('fake-batch-id');

        // Replace the batch lookup so it returns our fake batch.
        $this->instance(\Illuminate\Bus\BatchRepository::class, Mockery::mock(\Illuminate\Bus\BatchRepository::class, function ($mock) use ($fakeBatch) {
            $mock->shouldReceive('find')->with('fake-batch-id')->andReturn($fakeBatch);
        }));

        $job->handle();

        // No chunk files should have been created.
        Storage::disk('local')->assertMissing('bulk-dummy/multiguna/chunks/bulk-1/chunk-00001.json');
    }

    public function test_process_chunk_skips_when_file_already_deleted(): void
    {
        Storage::fake('local');

        // Chunk file does NOT exist — simulates retry after successful processing.
        $chunkPath = 'bulk-dummy/multiguna/chunks/bulk-1/chunk-00001.json';

        Log::shouldReceive('warning')
            ->once()
            ->with(Mockery::pattern('/already processed or missing/'), Mockery::on(function (array $ctx) {
                return $ctx['bulk_id'] === 'bulk-1' && $ctx['chunk'] === 1;
            }));

        // Should also log the "chunk processed" info with 0 rows.
        Log::shouldReceive('info')
            ->once()
            ->with('Dummy bulk multiguna chunk processed', Mockery::on(function (array $ctx) {
                return $ctx['processed'] === 0;
            }));

        $job = new ProcessMultigunaBulkDummyChunkJob('bulk-1', 1, $chunkPath, 'local');
        $job->handle();
    }

    private function readRows(string $path): array
    {
        $job = new DispatchMultigunaBulkDummyChunksJob('bulk-1', basename($path));
        $method = new ReflectionMethod($job, 'rowCursor');
        $method->setAccessible(true);

        return iterator_to_array($method->invoke($job, $path));
    }

    private function temporaryFile(string $name): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'multiguna-bulk-tests';

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return $directory.DIRECTORY_SEPARATOR.$name;
    }
}
