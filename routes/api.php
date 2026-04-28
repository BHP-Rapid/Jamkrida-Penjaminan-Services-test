<?php

use App\Http\Controllers\AjpServices\AjpController;
use App\Http\Controllers\CustomBondServices\CustomBondTransactionController;
use App\Http\Controllers\KonstruksiServices\KonstruksiTransactionController;
use App\Http\Controllers\KreditMikroKecilServices\KreditMikroKecilController;
use App\Http\Controllers\KreditUsahaServices\KreditUsahaController;
use App\Http\Controllers\KURServices\KURTransactionController;
use App\Http\Controllers\MultigunaServices\MultigunaController as MultigunaServicesMultigunaController;
use App\Http\Controllers\PaymentGatewayController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;
use App\Http\Controllers\SuretyBondTransactionServices\SuretyBondTransactionController;

Route::prefix('/v2/penjaminan')->group(function () {
    Route::get('/penjaminan-data', [PenjaminanTransactionController::class, 'index']);
    Route::get('/detail-additional-document', [PenjaminanTransactionController::class, 'getAdditionalDocProduct']);
    Route::get('/detail-certified-permohonan', [PenjaminanTransactionController::class, 'GetDetailCertificateByID']);
});

Route::prefix('/v2/payment-gateway')->group(function () {
    Route::post('/create-transaction', [PaymentGatewayController::class, 'createTransaction']);
    Route::post('/update-transaction/{id}', [PaymentGatewayController::class, 'updateTransaction']);
    Route::get('/transaction-status/{id}', [PaymentGatewayController::class, 'getTransactionStatus']);
});

// PENJAMINAN MULTIGUNA
Route::prefix('/v2/penjaminan/multiguna')->group(function () {
    Route::get('/detail/{id}', [MultigunaServicesMultigunaController::class, 'show'])
        ->middleware([
            'auth.context',
            'auth.role:admin,super_admin,admin_mitra',
            'auth.permission:PENJAMINAN,create',
        ]);
});

// PENJAMINAN AJP
Route::prefix('/v2/penjaminan/ajp')->group(function () {
    Route::get('/download-template', [AjpController::class, 'downloadAjpTemplate']);
    Route::post('/create', [AjpController::class, 'storeAjp']);
    Route::get('/detail/{trx_no}', [AjpController::class, 'show']);
    Route::get('/detail-payment', [AjpController::class, 'GetDetailPaymentAjp']);
    Route::get('/detail-payment-list', [AjpController::class, 'GetDetailListPaymentAjp']);
    Route::post('/upload-bukti-bayar-manual', [AjpController::class, 'uploadPembayaranManual']);
    Route::post('/approve-penjaminan', [AjpController::class, 'ApprovePenjaminanAJP']);
    Route::post('/update-draft/{trxNo}', [AjpController::class, 'updateAjp']);
    Route::get('/debt', [AjpController::class, 'createTrxDebitur']);
});

Route::prefix('/v2/penjaminan/custom-bond')->group(function () {
    Route::get('/penjaminan-custom-bond-byid', [CustomBondTransactionController::class, 'show']);
    Route::post('/create', [CustomBondTransactionController::class, 'store']);
    Route::post('/update-draft/{trxNo}', [CustomBondTransactionController::class, 'updateDraft']);
    Route::post('/approved-penjaminan', [CustomBondTransactionController::class, 'ApprovePenjaminanCSTB']);
    Route::post('/upload-bukti-bayar-manual', [CustomBondTransactionController::class, 'uploadPembayaranManual']);
    Route::post('/submit-draft/{trxNo}', [CustomBondTransactionController::class, 'submitDraft']);
    Route::get('/detail-payment-custom-bond', [CustomBondTransactionController::class, 'GetDetailPaymentCstb']);
});

// PENJAMINAN SURETY BOND
Route::prefix('/v2/penjaminan/surety-bond')->group(function () {
    Route::get('/detail', [SuretyBondTransactionController::class, 'show']);
    Route::post('/create', [SuretyBondTransactionController::class, 'store']);
    Route::post('/update-draft/{trxNo}', [SuretyBondTransactionController::class, 'update']);
    Route::post('/submit-draft/{trxNo}', [SuretyBondTransactionController::class, 'submitDraft']);
    Route::post('/approved-penjaminan', [SuretyBondTransactionController::class, 'approvePenjaminanSB']);
    Route::get('/detail-payment', [SuretyBondTransactionController::class, 'getDetailPaymentSrtb']);
    Route::post('/upload-bukti-bayar-manual', [SuretyBondTransactionController::class, 'uploadPembayaranManual']);
    Route::get('/detail-payment-surety-bond', [SuretyBondTransactionController::class, 'getDetailPaymentSrtb']);
});

Route::prefix('/v2/penjaminan/kredit-mikro-kecil')->group(function () {
    Route::post('/v2/penjaminan/kredit-mikro-kecil/create', [KreditMikroKecilController::class, 'store']);
    Route::post('/v2/penjaminan/kredit-mikro-kecil/approve-penjaminan', [KreditMikroKecilController::class, 'ApprovePenjaminanKMK']);
    Route::get('/v2/penjaminan/template-base/download-template', [KreditMikroKecilController::class, 'DownloadTemplateKMK']);
    Route::put('/v2/penjaminan/kredit-mikro-kecil/update-draft/{trxNo}', [KreditMikroKecilController::class, 'updateDraft']);
    Route::get('/v2/penjaminan/kredit-mikro-kecil/detail-full-kmk', [KreditMikroKecilController::class, 'GetDetailPaymentKMK']);
    Route::get('/v2/penjaminan/kredit-mikro-kecil/detail-installment-kmk-list', [KreditMikroKecilController::class, 'GetDetailListPaymentKMK']);
    Route::post('/v2/penjaminan/kredit-mikro-kecil/upload-bukti-bayar-manual', [KreditMikroKecilController::class, 'UploadPembayaranManualKMK']);
});

// Route::get('/v2/penjaminan/kredit-usaha-rakyat/detail/{id}', [KURTransactionController::class, 'show']);
Route::prefix('/v2/penjaminan/kredit-usaha-rakyat')->group(function () {
    Route::post('/create', [KURTransactionController::class, 'store']);
    Route::get('/detail/{id}', [KURTransactionController::class, 'show']);
});

//PENJAMINAN KONSTRUKSI
Route::post('/v2/penjaminan/konstruksi/create', [KonstruksiTransactionController::class, 'store']);
Route::get('/v2/penjaminan/konstruksi/download-template', [KonstruksiTransactionController::class, 'ExportKonstruksi']);
Route::get('/v2/penjaminan/konstruksi/detail/{id}', [KonstruksiTransactionController::class, 'show']);
Route::post('/v2/penjaminan/konstruksi/update-draft/{trxNo}', [KonstruksiTransactionController::class, 'updateDraft']);
Route::post('/v2/penjaminan/konstruksi/approve-penjaminan', [KonstruksiTransactionController::class, 'ApprovePenjaminan']);
Route::get('/v2/penjaminan/konstruksi/detail-payment', [KonstruksiTransactionController::class, 'GetDetailPaymentKonstruksi']);
Route::get('/v2/penjaminan/konstruksi/detail-payment-list', [KonstruksiTransactionController::class, 'GetDetailListPaymentKonstruksi']);

Route::post('/v2/penjaminan/konstruksi/upload-bukti-bayar-manual', [KonstruksiTransactionController::class, 'uploadPembayaranManual']);
Route::get('/v2/penjaminan/konstruksi/debt', [KonstruksiTransactionController::class, 'createTrxDebitur']);

// PENJAMINAN KREDIT USAHA
Route::prefix('/v2/penjaminan/kredit-usaha')->group(function () {
    Route::post('/create', [KreditUsahaController::class, 'store']);
    Route::get('/show/{id}', [KreditUsahaController::class, 'show']);
    Route::post('/update-draft/{trxNo}', [KreditUsahaController::class, 'updateKreditUsaha']);
    Route::post('/approve-penjaminan', [KreditUsahaController::class, 'ApprovePenjaminanKreditUsaha']);
    Route::get('/detail-payment-kredit-usaha', [KreditUsahaController::class, 'GetDetailPaymentKreditUsaha']);
    Route::get('/detail-payment-kredit-usaha-list', [KreditUsahaController::class, 'GetDetailListPaymentKreditUsaha']);
    Route::post('/upload-bukti-bayar-manual', [KreditUsahaController::class, 'uploadPembayaranManual']);
});
