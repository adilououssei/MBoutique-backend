<?php

use App\Modules\Devices\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

/*
| auth:sanctum -> store -> feature -> Policy. Les ventes et achats d'appareils
| passent par Sales et Suppliers (DeviceService) ; ici : recherche, fiche,
| identification d'unités, changement d'état.
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:appareils')->prefix('appareils')->name('appareils.')->group(function () {
        Route::get('/', [DeviceController::class, 'index'])->name('index');
        Route::post('/', [DeviceController::class, 'store'])->name('store');
        Route::get('/{device}', [DeviceController::class, 'show'])->whereNumber('device')->name('show');
        Route::put('/{device}', [DeviceController::class, 'update'])->whereNumber('device')->name('update');
        Route::post('/{device}/statut', [DeviceController::class, 'changeStatus'])->whereNumber('device')->name('statut');
    });
});
