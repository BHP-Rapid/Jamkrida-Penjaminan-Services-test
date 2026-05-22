<?php

namespace Tests\Feature;

use App\Jobs\DispatchMultigunaBulkDummyChunksJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MultigunaBulkDummyControllerTest extends TestCase
{
    public function test_bulk_dummy_upload_stores_file_and_dispatches_batch(): void
    {
        Bus::fake();
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent(
            'bulk_multiguna.csv',
            "No surat permohonan;Jenis product;bank;Nama Makful Anhu;NIK;Plafond Pembiayaan\n".
            "KMK202604220;Multiguna;mandiri;Ahmad Fauzi;4332181960013389;41867825\n",
        );

        $response = $this->post('/api/v2/penjaminan/multiguna/bulk-dummy', [
            'file' => $file,
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queue', 'bulk-multiguna')
            ->assertJsonPath('data.mode', 'dummy')
            ->assertJsonPath('data.writes_domain_database', false);

        Storage::disk('local')->assertExists($response->json('data.stored_path'));

        Bus::assertBatched([
            fn (DispatchMultigunaBulkDummyChunksJob $job) => $job->filePath === $response->json('data.stored_path')
                && $job->disk === 'local'
                && $job->originalName === 'bulk_multiguna.csv',
        ]);
    }

    public function test_bulk_dummy_upload_requires_file(): void
    {
        $response = $this->postJson('/api/v2/penjaminan/multiguna/bulk-dummy');

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
