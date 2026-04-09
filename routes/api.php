<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PenjaminanTransactionController;

Route::get('/penjaminan/penjaminan-data', [PenjaminanTransactionController::class, 'index']);