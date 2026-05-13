<?php

use App\Http\Controllers\AjpServices\AjpController;
use App\Http\Controllers\CustomBondServices\CustomBondTransactionController;
use App\Http\Controllers\KbgServices\KBGTransactionController;
use App\Http\Controllers\KonstruksiServices\KonstruksiTransactionController;
use App\Http\Controllers\KreditMikroKecilServices\KreditMikroKecilController;
use App\Http\Controllers\KreditUsahaServices\KreditUsahaController;
use App\Http\Controllers\KURServices\KURTransactionController;
use App\Http\Controllers\MultigunaServices\MultigunaController as MultigunaServicesMultigunaController;
use App\Http\Controllers\PaymentGatewayController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;
use App\Http\Controllers\SuretyBondTransactionServices\SuretyBondTransactionController;

/*
auth verification 101:
    option:
        auth.role: 
        (example:      [  akun role yg ingin di allow     ]
            'auth.role:admin,super_admin,admin_mitra,mitra',
        )
        -admin
        -super_admin
        -admin
        -admin_mitra

        auth.permission:
        (example:            [  menu_code   ] [      permission access         ]                          
            'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
        )
        opsi pertama adalah menu_code (lihat di table mitra_portal.master_menus_v2) => assign API hanya untuk 1 halaman saja 
        -view
        -create
        -update
        -delete
        -approve
*/

Route::prefix('/v2/penjaminan')->group(function () {
    Route::get('/penjaminan-data', [PenjaminanTransactionController::class, 'index'])
        ->middleware([
            'auth.context',
            'auth.role:admin,super_admin,admin_mitra,mitra,head_admin_mitra',
            'auth.permission:mitra=mitra.penjaminan:view,create,update,delete|head_admin_mitra=mitra.approve.penjaminan:view,approve',
        ]);
    Route::get('/detail-additional-document', [PenjaminanTransactionController::class, 'getAdditionalDocument'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra=mitra.penjaminan:view,update',
    ]);
    Route::get('/detail-certified-permohonan', [PenjaminanTransactionController::class, 'GetDetailCertificateByID'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra=mitra.penjaminan:view,create,update,delete,approve',
    ]);
    Route::get('/get-pks-data', [PenjaminanTransactionController::class, 'getPenjaminanPks'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra=mitra.penjaminan:view,create,update,delete,approve',
    ]);
    Route::post('/upload-additional-document', [PenjaminanTransactionController::class, 'uploadAdditionalDoc'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra=mitra.penjaminan:view,create,update,delete,approve',
    ]);
});

Route::prefix('/v2/payment-gateway')->group(function () {
    Route::post('/create-transaction', [PaymentGatewayController::class, 'createTransaction'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-transaction/{id}', [PaymentGatewayController::class, 'updateTransaction'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/transaction-status/{id}', [PaymentGatewayController::class, 'getTransactionStatus'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

// PENJAMINAN MULTIGUNA
Route::prefix('/v2/penjaminan/multiguna')->group(function () {
    Route::get('/detail/{trx_no}', [MultigunaServicesMultigunaController::class, 'show'])
        ->middleware([
            'auth.context',
            'auth.role:admin,super_admin,admin_mitra,mitra',
            'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
        ]);
    Route::get('/detail-payment-multiguna-list', [MultigunaServicesMultigunaController::class, 'GetDetailListPaymentMultiguna'])
        ->middleware([
            'auth.context',
            'auth.role:admin,super_admin,admin_mitra,mitra',
            'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
        ]);

    Route::get('/detail-payment-multiguna', [MultigunaServicesMultigunaController::class, 'GetDetailPaymentMultiguna'])
        ->middleware([
            'auth.context',
            'auth.role:admin,super_admin,admin_mitra,mitra',
            'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
        ]);
});

// PENJAMINAN AJP
Route::prefix('/v2/penjaminan/ajp')->group(function () {
    Route::get('/download-template', [AjpController::class, 'downloadAjpTemplate'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/create', [AjpController::class, 'storeAjp'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail/{trx_no}', [AjpController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment', [AjpController::class, 'GetDetailPaymentAjp'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-list', [AjpController::class, 'GetDetailListPaymentAjp'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/upload-bukti-bayar-manual', [AjpController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [AjpController::class, 'ApprovePenjaminanAJP'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trxNo}', [AjpController::class, 'updateAjp'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/debt', [AjpController::class, 'createTrxDebitur'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

Route::prefix('/v2/penjaminan/custom-bond')->group(function () {
    Route::get('/penjaminan-custom-bond-byid', [CustomBondTransactionController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/create', [CustomBondTransactionController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trxNo}', [CustomBondTransactionController::class, 'updateDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approved-penjaminan', [CustomBondTransactionController::class, 'ApprovePenjaminanCSTB'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    Route::post('/upload-bukti-bayar-manual', [CustomBondTransactionController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/submit-draft/{trxNo}', [CustomBondTransactionController::class, 'submitDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-custom-bond', [CustomBondTransactionController::class, 'GetDetailPaymentCstb'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

// PENJAMINAN SURETY BOND
Route::prefix('/v2/penjaminan/surety-bond')->group(function () {
    Route::get('/detail', [SuretyBondTransactionController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra,head_admin_mitra',
        // 'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
        'auth.permission:mitra=mitra.penjaminan:view,create,update,delete|head_admin_mitra=mitra.approve.penjaminan:view,approve',
    ]);
    Route::post('/create', [SuretyBondTransactionController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trxNo}', [SuretyBondTransactionController::class, 'update'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/submit-draft/{trxNo}', [SuretyBondTransactionController::class, 'submitDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approved-penjaminan', [SuretyBondTransactionController::class, 'approvePenjaminanSB'])->middleware([
        'auth.context',
        'auth.role:super_admin,head_admin_mitra',
        'auth.permission:head_admin_mitra=mitra.approve.penjaminan:view,approve',
        // 'auth.role:admin,super_admin,admin_mitra,mitra',
        // 'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    // Route::get('/detail-payment', [SuretyBondTransactionController::class, 'getDetailPaymentSrtb'])->middleware([
    //     'auth.context',
    //     'auth.role:admin,super_admin,admin_mitra,mitra',
    //     'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    // ]);
    Route::post('/upload-bukti-bayar-manual', [SuretyBondTransactionController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-surety-bond', [SuretyBondTransactionController::class, 'getDetailPaymentSrtb'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

Route::prefix('/v2/penjaminan/kredit-mikro-kecil')->group(function () {
    Route::post('/create', [KreditMikroKecilController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [KreditMikroKecilController::class, 'ApprovePenjaminanKMK'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    Route::get('/template-base/download-template', [KreditMikroKecilController::class, 'DownloadTemplateKMK'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-kmk', [KreditMikroKecilController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::put('/update-draft/{trxNo}', [KreditMikroKecilController::class, 'updateDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-full-kmk', [KreditMikroKecilController::class, 'GetDetailPaymentKMK'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-installment-kmk-list', [KreditMikroKecilController::class, 'GetDetailListPaymentKMK'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);

    Route::post('/upload-bukti-bayar-manual', [KreditMikroKecilController::class, 'UploadPembayaranManualKMK'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

// Route::get('/v2/penjaminan/kredit-usaha-rakyat/detail/{id}', [KURTransactionController::class, 'show']);
Route::prefix('/v2/penjaminan/kredit-usaha-rakyat')->group(function () {
    Route::post('/create', [KURTransactionController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail/{id}', [KURTransactionController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('update-draft/{trxNo}', [KURTransactionController::class, 'updateDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [KURTransactionController::class, 'approvePenjaminan'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    Route::post('/upload-bukti-bayar-manual', [KURTransactionController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-kur', [KURTransactionController::class, 'getDetailPaymentKUR'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-split-payment-kur', [KURTransactionController::class, 'getDetailSplitPaymentKUR'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

Route::prefix('/v2/penjaminan/kontra-bank-garansi')->group(function () {
    Route::post('/create', [KBGTransactionController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail/{trx_no}', [KBGTransactionController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trx_no}', [KBGTransactionController::class, 'updateKbg'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/submit-draft/{trx_no}', [KBGTransactionController::class, 'submitDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-kontra-bank-garansi', [KBGTransactionController::class, 'getDetailPaymentKbg'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/upload-bukti-bayar-manual', [KBGTransactionController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [KBGTransactionController::class, 'approvePenjaminanKBG'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

//PENJAMINAN KONSTRUKSI
Route::prefix('/v2/penjaminan/konstruksi')->group(function () {
    Route::post('/create', [KonstruksiTransactionController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/download-template', [KonstruksiTransactionController::class, 'ExportKonstruksi'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail/{id}', [KonstruksiTransactionController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trxNo}', [KonstruksiTransactionController::class, 'updateDraft'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [KonstruksiTransactionController::class, 'ApprovePenjaminan'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment', [KonstruksiTransactionController::class, 'GetDetailPaymentKonstruksi'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-list', [KonstruksiTransactionController::class, 'GetDetailListPaymentKonstruksi'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);

    Route::post('/upload-bukti-bayar-manual', [KonstruksiTransactionController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/debt', [KonstruksiTransactionController::class, 'createTrxDebitur'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});

// PENJAMINAN KREDIT USAHA
Route::prefix('/v2/penjaminan/kredit-usaha')->group(function () {
    Route::post('/create', [KreditUsahaController::class, 'store'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/show/{id}', [KreditUsahaController::class, 'show'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/update-draft/{trxNo}', [KreditUsahaController::class, 'updateKreditUsaha'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/approve-penjaminan', [KreditUsahaController::class, 'ApprovePenjaminanKreditUsaha'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan.approval,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-kredit-usaha', [KreditUsahaController::class, 'GetDetailPaymentKreditUsaha'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::get('/detail-payment-kredit-usaha-list', [KreditUsahaController::class, 'GetDetailListPaymentKreditUsaha'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
    Route::post('/upload-bukti-bayar-manual', [KreditUsahaController::class, 'uploadPembayaranManual'])->middleware([
        'auth.context',
        'auth.role:admin,super_admin,admin_mitra,mitra',
        'auth.permission:mitra.penjaminan,read,create,update,delete,approve',
    ]);
});
