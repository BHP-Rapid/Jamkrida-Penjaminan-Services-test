<?php

use App\Http\Controllers\CustomBondServices\CustomBondTransactionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;

Route::get('/penjaminan/penjaminan-data', [PenjaminanTransactionController::class, 'index']);
Route::get('/penjaminan/detail-additional-document', [PenjaminanTransactionController::class, 'getAdditionalDocProduct']);
Route::get('/penjaminan/detail-certified-permohonan',[PenjaminanTransactionController::class, 'GetDetailCertificateByID']);

Route::get('/v2/penjaminan/penjaminan-custom-bond-byid', [CustomBondTransactionController::class, 'show']);
Route::post('/v2/penjaminan/custom-bond/create', [CustomBondTransactionController::class, 'store']);
Route::post('/v2/penjaminan/custom-bond/update-draft/{trxNo}', [CustomBondTransactionController::class, 'updateDraft']);
