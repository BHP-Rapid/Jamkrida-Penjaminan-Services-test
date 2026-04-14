<?php

use App\Http\Controllers\MultigunaController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;

Route::get('/penjaminan/penjaminan-data', [PenjaminanTransactionController::class, 'index']);
Route::get('/penjaminan/detail-additional-document', [PenjaminanTransactionController::class, 'getAdditionalDocProduct']);
Route::get('/penjaminan/detail-certified-permohonan', [PenjaminanTransactionController::class, 'GetDetailCertificateByID']);



// PENJAMINAN MULTIGUNA
Route::prefix('/v2/penjaminan/multiguna')->group(function () {
    Route::get('/detail/{id}', [MultigunaController::class, 'show']);
});

// Route::post('/v2/penjaminan/multiguna/create', [MultigunaTransactionController::class, 'store']);
// Route::post('/v2/penjaminan/multiguna/approve-penjaminan', [MultigunaTransactionController::class, 'ApprovePenjaminanMultiguna']);
// Route::post('/v2/penjaminan/full-payment-multiguna', [MultigunaTransactionController::class, 'MultigunaPayment']);
// Route::post('/v2/penjaminan/payment-multiguna-split', [MultigunaTransactionController::class, 'MultigunaPaymentSplit']);

// Route::put('/v2/penjaminan/multiguna/update-draft/{trxNo}', [MultigunaTransactionController::class, 'updateDraft']);
// Route::get('/v2/penjaminan/payment-status/{orderId}', [MultigunaTransactionController::class, 'getMidTransPayMentStatus']);
// Route::post('/v2/penjaminan/multiguna/bulk-upload', [BulkUploadPnjV2Controller::class, 'UploadFormDataStaging']);
// Route::get('/v2/penjaminan/multiguna/bulk-get', [BulkUploadPnjV2Controller::class, 'index']);
// Route::post('/v2/penjaminan/multiguna/bulk-attachment', [BulkUploadPnjV2Controller::class, 'updateAttachments']);
// Route::get('/v2/penjaminan/multiguna/bulk-template', [BulkUploadPnjV2Controller::class, 'template']);
// // Route::post("/v2/penjaminan/multiguna/bulk-validate", [BulkUploadPnjV2Controller::class, 'validateStgData']);
// Route::get('/v2/penjaminan/multiguna/bulk-getbyid', [BulkUploadPnjV2Controller::class, 'getById']);
// Route::post('/v2/penjaminan/multiguna/bulk-delete', [BulkUploadPnjV2Controller::class, 'delete']);
