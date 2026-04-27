<?php

use App\Http\Controllers\AjpServices\AjpController;
use App\Http\Controllers\CustomBondServices\CustomBondTransactionController;
use App\Http\Controllers\KonstruksiServices\KonstruksiTransactionController;
use App\Http\Controllers\KreditMikroKecilServices\KreditMikroKecilController;
use App\Http\Controllers\KreditUsahaServices\KreditUsahaController;
use App\Http\Controllers\KURServices\KURTransactionController;
use App\Http\Controllers\MultigunaController;
use App\Http\Controllers\MultigunaServices\MultigunaController as MultigunaServicesMultigunaController;
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

Route::get('/v2/penjaminan/kredit-usaha-rakyat/detail/{id}', [KURTransactionController::class, 'show']);

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
Route::post('/v2/penjaminan/kredit-usaha/create', [KreditUsahaController::class, 'store']);
Route::get('/v2/penjaminan/kredit-usaha/show/{id}', [KreditUsahaController::class, 'show']);
Route::post('/v2/penjaminan/kredit-usaha/update-draft/{trxNo}', [KreditUsahaController::class, 'updateKreditUsaha']);
Route::post('/v2/penjaminan/kredit-usaha/approve-penjaminan', [KreditUsahaController::class, 'ApprovePenjaminanKreditUsaha']);
Route::get('/v2/penjaminan/kredit-usaha/detail-payment-kredit-usaha', [KreditUsahaController::class, 'GetDetailPaymentKreditUsaha']);
Route::get('/v2/penjaminan/kredit-usaha/detail-payment-kredit-usaha-list', [KreditUsahaController::class, 'GetDetailListPaymentKreditUsaha']);
Route::post('/v2/penjaminan/kredit-usaha/upload-bukti-bayar-manual', [KreditUsahaController::class, 'uploadPembayaranManual']);