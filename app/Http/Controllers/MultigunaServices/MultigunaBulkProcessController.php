<?php

namespace App\Http\Controllers\MultigunaServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchPenjaminanMultigunaBulkChunksJob;
use App\Models\NotifMitra;
use App\Models\v2\BulkStgMultigunaModel;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MultigunaBulkProcessController extends Controller
{
    public function store(Request $request)
    {
        try {
            $payload = $request->validate([
                'bulk_no' => ['required', 'string'],
                'user_id' => ['required', 'string'],
                'user_name' => ['required', 'string'],
                'tenant_id' => ['required', 'string'],
                'mitra_id' => ['required', 'string'],
            ]);

            $bulkNo = $payload['bulk_no'];
            $userId = $payload['user_id'];
            $userName = $payload['user_name'];
            $tenantId = $payload['tenant_id'];
            $mitraId = $payload['mitra_id'];

            $query = BulkStgMultigunaModel::query()
                ->where('tenant_id', $tenantId)
                ->where('mitra_id', $mitraId)
                ->where('bulk_no', $bulkNo);

            $totalRecords = (clone $query)->count();
            if ($totalRecords === 0) {
                return ApiResponse::error(
                    'Data staging tidak ditemukan untuk bulk_no '.$bulkNo.'.',
                    404,
                );
            }

            if ((clone $query)->whereIn('status', ['queued', 'processing'])->exists()) {
                return ApiResponse::success([
                    'bulk_no' => $bulkNo,
                    'queue' => 'bulk-multiguna',
                    'total_records' => $totalRecords,
                    'already_queued' => true,
                ], 'Bulk Multiguna No '.$bulkNo.' sudah masuk antrean proses.', 202);
            }

            (clone $query)->update([
                'status' => 'queued',
                'updated_at' => Carbon::now('Asia/Jakarta'),
            ]);

            try {
                $batch = Bus::batch([
                    new DispatchPenjaminanMultigunaBulkChunksJob(
                        $bulkNo,
                        $userName,
                        $userId,
                        $mitraId,
                        $tenantId,
                    ),
                ])
                    ->name('Bulk Penjaminan Multiguna '.$bulkNo)
                    ->onQueue('bulk-multiguna')
                    ->then(static function (Batch $batch) use ($bulkNo, $mitraId, $tenantId, $userId): void {
                        Log::info('Bulk penjaminan multiguna batch completed.', [
                            'bulk_no' => $bulkNo,
                            'batch_id' => $batch->id,
                            'tenant_id' => $tenantId,
                            'mitra_id' => $mitraId,
                        ]);

                        NotifMitra::create([
                            'mitra_user_id' => $userId,
                            'title' => 'Bulk Penjaminan Process Completed',
                            'message' => 'Bulk '.$bulkNo.' finished processing.',
                            'type' => 'Penjaminan Process',
                            'is_read' => false,
                        ]);

                        BulkStgMultigunaModel::query()
                            ->where('tenant_id', $tenantId)
                            ->where('mitra_id', $mitraId)
                            ->where('bulk_no', $bulkNo)
                            ->delete();
                    })
                    ->catch(static function (Batch $batch, Throwable $exception) use ($bulkNo, $mitraId, $tenantId, $userId): void {
                        Log::error('Bulk penjaminan multiguna batch failed.', [
                            'bulk_no' => $bulkNo,
                            'batch_id' => $batch->id,
                            'tenant_id' => $tenantId,
                            'mitra_id' => $mitraId,
                            'message' => $exception->getMessage(),
                            'exception' => $exception,
                        ]);

                        BulkStgMultigunaModel::query()
                            ->where('tenant_id', $tenantId)
                            ->where('mitra_id', $mitraId)
                            ->where('bulk_no', $bulkNo)
                            ->update([
                                'status' => 'failed',
                                'updated_at' => Carbon::now('Asia/Jakarta'),
                            ]);

                        NotifMitra::create([
                            'mitra_user_id' => $userId,
                            'title' => 'Bulk Penjaminan Process Failed',
                            'message' => 'Bulk '.$bulkNo.' error processing. '.$exception->getMessage(),
                            'type' => 'ERROR Penjaminan Process',
                            'is_read' => false,
                        ]);
                    })
                    ->dispatch();
            } catch (Throwable $exception) {
                (clone $query)->update([
                    'status' => 'failed',
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);

                throw $exception;
            }

            Log::info('Bulk penjaminan multiguna API dispatched batch.', [
                'bulk_no' => $bulkNo,
                'batch_id' => $batch->id,
                'tenant_id' => $tenantId,
                'mitra_id' => $mitraId,
                'records' => $totalRecords,
            ]);

            return ApiResponse::success([
                'bulk_no' => $bulkNo,
                'batch_id' => $batch->id,
                'queue' => 'bulk-multiguna',
                'total_records' => $totalRecords,
                'already_queued' => false,
            ], 'Bulk Multiguna No '.$bulkNo.' diterima dan sedang diproses.', 202);
        } catch (ValidationException $exception) {
            return ApiResponse::error('Validation error', 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('Failed to dispatch bulk penjaminan multiguna API process.', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return ApiResponse::error('Gagal memulai proses bulk Multiguna: '.$exception->getMessage(), 500);
        }
    }
}
