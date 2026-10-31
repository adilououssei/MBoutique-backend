<?php

use App\Modules\Warranties\Http\Controllers\WarrantyController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:garanties')->prefix('garanties')->name('garanties.')->group(function () {
        Route::get('/', [WarrantyController::class, 'index'])->name('index');
        Route::get('/{warranty}', [WarrantyController::class, 'show'])->whereNumber('warranty')->name('show');
        Route::post('/{warranty}/reclamations', [WarrantyController::class, 'claim'])->whereNumber('warranty')->name('reclamations.store');
        Route::put('/{warranty}/reclamations/{claim}', [WarrantyController::class, 'followUp'])->whereNumber('warranty')->withoutScopedBindings()->name('reclamations.update');
    });
});
