<?php

namespace App\Http\Controllers\MultigunaServices;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Jobs\DispatchPenjaminanMultigunaBulkChunksJob;
use App\Models\v2\BulkStgMultigunaModel;
use App\Services\AuthInternalClient;
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

            $authUser = $request->attributes->get('auth_user', []);
            $authUser = is_array($authUser) ? $authUser : [];
            $userToken = (string) ($request->attributes->get('auth_token') ?: $request->bearerToken());
            $bulkNo = $payload['bulk_no'];
            $userId = (string) ($authUser['user_id'] ?? $payload['user_id']);
            $userName = (string) ($authUser['name'] ?? $payload['user_name']);
            $authMitraId = (string) ($authUser['mitra_id'] ?? '');
            $tenantMitra = [];

            if ($authMitraId !== '' && $userToken !== '') {
                $tenantMitraResponse = app(AuthInternalClient::class)->getTenantMitra($authMitraId, $userToken);
                $tenantMitra = is_array($tenantMitraResponse['data'] ?? null) ? $tenantMitraResponse['data'] : [];
            }

            $tenantId = (string) ($tenantMitra['tenant_id'] ?? $authUser['tenant_id'] ?? $payload['tenant_id']);
            $mitraId = (string) ($tenantMitra['alias'] ?? $payload['mitra_id']);

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
                        $userToken,
                        $authMitraId,
                    ),
                ])
                    ->name('Bulk Penjaminan Multiguna '.$bulkNo)
                    ->onQueue('bulk-multiguna')
                    ->then(static function (Batch $batch) use ($bulkNo, $mitraId, $tenantId, $userId, $userToken): void {
                        Log::info('Bulk penjaminan multiguna batch completed.', [
                            'bulk_no' => $bulkNo,
                            'batch_id' => $batch->id,
                            'tenant_id' => $tenantId,
                            'mitra_id' => $mitraId,
                        ]);

                        try {
                            app(AuthInternalClient::class)->createMitraNotification(
                                $userId,
                                'Bulk Penjaminan Process Completed',
                                'Bulk '.$bulkNo.' finished processing.',
                                $userToken,
                            );
                        } catch (Throwable $exception) {
                            Log::warning('Failed to create bulk penjaminan multiguna success notification in Auth Master.', [
                                'bulk_no' => $bulkNo,
                                'tenant_id' => $tenantId,
                                'mitra_id' => $mitraId,
                                'user_id' => $userId,
                                'message' => $exception->getMessage(),
                            ]);
                        }

                        BulkStgMultigunaModel::query()
                            ->where('tenant_id', $tenantId)
                            ->where('mitra_id', $mitraId)
                            ->where('bulk_no', $bulkNo)
                            ->delete();
                    })
                    ->catch(static function (Batch $batch, Throwable $exception) use ($bulkNo, $mitraId, $tenantId, $userId, $userToken): void {
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

                        try {
                            app(AuthInternalClient::class)->createMitraNotification(
                                $userId,
                                'Bulk Penjaminan Process Failed',
                                'Bulk '.$bulkNo.' error processing. '.$exception->getMessage(),
                                $userToken,
                            );
                        } catch (Throwable $notificationException) {
                            Log::warning('Failed to create bulk penjaminan multiguna failure notification in Auth Master.', [
                                'bulk_no' => $bulkNo,
                                'tenant_id' => $tenantId,
                                'mitra_id' => $mitraId,
                                'user_id' => $userId,
                                'message' => $notificationException->getMessage(),
                            ]);
                        }
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
