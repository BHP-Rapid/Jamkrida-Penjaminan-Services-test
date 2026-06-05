<?php

use App\Http\Controllers\MultigunaServices\MultigunaBulkDummyController;
use App\Http\Controllers\MultigunaServices\MultigunaBulkProcessController;
use Illuminate\Support\Facades\Route;

Route::post('/v2/penjaminan/multiguna/bulk-dummy', [MultigunaBulkDummyController::class, 'store']);
Route::post('/v2/penjaminan/multiguna/bulk-process', [MultigunaBulkProcessController::class, 'store'])
    ->middleware([
        'auth.context',
        'auth.role:mitra,head_admin_mitra',
        'auth.permission:mitra=mitra.claim:view,create|head_admin_mitra=mitra.approve.claim:view',
    ]);
