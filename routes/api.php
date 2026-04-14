<?php

use App\Http\Controllers\CustomBondServices\CustomBondTransactionController;
use App\Http\Controllers\KreditMikroKecilServices\KreditMikroKecilController;
use App\Http\Controllers\MultigunaController;
use App\Http\Controllers\PaymentGatewayController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;
use App\Http\Controllers\SuretyBondTransactionServices\SuretyBondTransactionController;

Route::get('/penjaminan/penjaminan-data', [PenjaminanTransactionController::class, 'index']);
Route::get('/penjaminan/detail-additional-document', [PenjaminanTransactionController::class, 'getAdditionalDocProduct']);
Route::get('/penjaminan/detail-certified-permohonan', [PenjaminanTransactionController::class, 'GetDetailCertificateByID']);

Route::post('/penjaminan/payment-gateway', [PaymentGatewayController::class, 'generatePaymentGateway']);
Route::post('/penjaminan/cancel-payment', [PaymentGatewayController::class, 'cancelPaymentMidtrans']);
Route::post('/penjaminan/renew-payment-token', [PaymentGatewayController::class, 'RenewPaymentGateway']);
Route::post('/penjaminan/validate-payment', [PaymentGatewayController::class, 'CheckPaymentMidtrans']);

// PENJAMINAN MULTIGUNA
Route::prefix('/v2/penjaminan/multiguna')->group(function () {
    Route::get('/detail/{id}', [MultigunaController::class, 'show']);
});

Route::get('/v2/penjaminan/penjaminan-custom-bond-byid', [CustomBondTransactionController::class, 'show']);
Route::post('/v2/penjaminan/custom-bond/create', [CustomBondTransactionController::class, 'store']);
Route::post('/v2/penjaminan/custom-bond/update-draft/{trxNo}', [CustomBondTransactionController::class, 'updateDraft']);
Route::post('/v2/penjaminan/custom-bond/approved-penjaminan', [CustomBondTransactionController::class, 'ApprovePenjaminanCSTB']);
Route::post('/v2/penjaminan/custom-bond/upload-bukti-bayar-manual', [CustomBondTransactionController::class, 'uploadPembayaranManual']);
Route::post('/v2/penjaminan/custom-bond/submit-draft/{trxNo}', [CustomBondTransactionController::class, 'submitDraft']);
Route::get('/v2/penjaminan/detail-payment-custom-bond', [CustomBondTransactionController::class, 'GetDetailPaymentCstb']);

Route::get('/v2/penjaminan/surety-bond/detail', [SuretyBondTransactionController::class, 'show']);
Route::post('/v2/penjaminan/surety-bond/create', [SuretyBondTransactionController::class, 'store']);
Route::post('/v2/penjaminan/surety-bond/update-draft/{trxNo}', [SuretyBondTransactionController::class, 'update']);
Route::post('/v2/penjaminan/surety-bond/submit-draft/{trxNo}', [SuretyBondTransactionController::class, 'submitDraft']);
Route::post('/v2/penjaminan/surety-bond/approved-penjaminan', [SuretyBondTransactionController::class, 'approvePenjaminannSB']);
Route::get('/v2/penjaminan/detail-payment-surety-bond', [SuretyBondTransactionController::class, 'getDetailPaymentSrtb']);
Route::post('/v2/penjaminan/surety-bond/upload-bukti-bayar-manual', [SuretyBondTransactionController::class, 'uploadPembayaranManual']);

Route::post('/v2/penjaminan/kredit-mikro-kecil/create', [KreditMikroKecilController::class, 'store']);
Route::post('/v2/penjaminan/kredit-mikro-kecil/approve-penjaminan', [KreditMikroKecilController::class, 'ApprovePenjaminanKMK']);
Route::get('/v2/penjaminan/template-base/download-template', [KreditMikroKecilController::class, 'DownloadTemplateKMK']);
Route::put('/v2/penjaminan/kredit-mikro-kecil/update-draft/{trxNo}', [KreditMikroKecilController::class, 'updateDraft']);
Route::get('/v2/penjaminan/kredit-mikro-kecil/detail-full-kmk', [KreditMikroKecilController::class, 'GetDetailPaymentKMK']);
Route::get('/v2/penjaminan/kredit-mikro-kecil/detail-installment-kmk-list', [KreditMikroKecilController::class, 'GetDetailListPaymentKMK']);
Route::post('/v2/penjaminan/kredit-mikro-kecil/upload-bukti-bayar-manual', [KreditMikroKecilController::class, 'UploadPembayaranManualKMK']);
