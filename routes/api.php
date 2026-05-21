<?php

use App\Http\Controllers\MultigunaServices\MultigunaBulkDummyController;
use Illuminate\Support\Facades\Route;

Route::post('/v2/penjaminan/multiguna/bulk-dummy', [MultigunaBulkDummyController::class, 'store']);
