<?php

use App\Modules\Returns\Http\Controllers\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:retours')->prefix('retours')->name('retours.')->group(function () {
        Route::get('/', [ReturnController::class, 'index'])->name('index');
        Route::post('/', [ReturnController::class, 'store'])->name('store');
        Route::post('/echange', [ReturnController::class, 'exchange'])->name('echange');
        Route::post('/{saleReturn}/verification', [ReturnController::class, 'verify'])->whereNumber('saleReturn')->name('verification');
    });
});
