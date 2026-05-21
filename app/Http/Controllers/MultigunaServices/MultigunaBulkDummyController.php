<?php

namespace App\Http\Controllers\MultigunaServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchMultigunaBulkDummyChunksJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MultigunaBulkDummyController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'file' => [
                    'required',
                    'file',
                    'max:204800',
                ],
            ]);

            $file = $validated['file'];
            $bulkId = (string) Str::uuid();
            $extension = $file->getClientOriginalExtension() ?: 'csv';
            $storedPath = $file->storeAs(
                'bulk-dummy/multiguna',
                $bulkId . '.' . $extension,
                'local',
            );

            if ($storedPath === false) {
                return ApiResponse::error('File gagal disimpan untuk proses dummy bulk.', 500);
            }

            $batch = Bus::batch([
                new DispatchMultigunaBulkDummyChunksJob(
                    $bulkId,
                    $storedPath,
                    'local',
                    $file->getClientOriginalName(),
                ),
            ])
                ->name('Dummy Bulk Multiguna ' . $bulkId)
                ->onQueue('bulk-multiguna')
                ->allowFailures()
                ->dispatch();

            Log::info('Dummy bulk multiguna batch dispatched', [
                'bulk_id' => $bulkId,
                'batch_id' => $batch->id,
                'file' => $file->getClientOriginalName(),
                'path' => $storedPath,
                'database_write' => false,
            ]);

            return ApiResponse::success([
                'bulk_id' => $bulkId,
                'batch_id' => $batch->id,
                'queue' => 'bulk-multiguna',
                'file' => $file->getClientOriginalName(),
                'stored_path' => $storedPath,
                'mode' => 'dummy',
                'writes_domain_database' => false,
            ], 'File diterima. Batch job dummy Multiguna sedang diproses.', 202);
        } catch (ValidationException $exception) {
            return ApiResponse::error('Validation error', 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch dummy bulk multiguna batch', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return ApiResponse::error('Gagal memulai proses dummy bulk: ' . $exception->getMessage(), 500);
        }
    }
}
