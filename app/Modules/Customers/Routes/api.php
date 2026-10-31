<?php

use App\Modules\Customers\Http\Controllers\CustomerAccountController;
use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Customers\Http\Controllers\CustomerStatsController;
use App\Modules\Customers\Http\Controllers\PrescriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers routes
|--------------------------------------------------------------------------
|
| Same chain as every other tenant-scoped module: auth:sanctum -> store
| (TenantContext) -> feature:clients -> Policy inside the controller.
| scopeBindings() gives {customer} the same "404, not a cross-store leak"
| guarantee as Product/Service/Category — see docs/customers.md.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:clients')->group(function () {
        Route::apiResource('clients', CustomerController::class)
            ->parameters(['clients' => 'customer']);

        // Compte client (crédit) — docs/customers.md §12.
        Route::get('clients/{customer}/compte', [CustomerAccountController::class, 'show']);
        Route::get('clients/{customer}/statistiques', [CustomerStatsController::class, 'show']);
        Route::post('clients/{customer}/paiements', [CustomerAccountController::class, 'pay']);
    });
});

Route::scopeBindings()->middleware(['auth:sanctum', 'store', 'feature:ordonnances'])->prefix('boutiques/{store}/ordonnances')->name('ordonnances.')->group(function () {
    Route::get('/', [PrescriptionController::class, 'index'])->name('index');
    Route::post('/', [PrescriptionController::class, 'store'])->name('store');
    Route::get('/{prescription}', [PrescriptionController::class, 'show'])->whereNumber('prescription')->name('show');
    Route::put('/{prescription}', [PrescriptionController::class, 'update'])->whereNumber('prescription')->name('update');
    Route::post('/{prescription}/annuler', [PrescriptionController::class, 'cancel'])->whereNumber('prescription')->name('annuler');
    Route::post('/{prescription}/photo', [PrescriptionController::class, 'photo'])->whereNumber('prescription')->name('photo.store');
    Route::get('/{prescription}/photo', [PrescriptionController::class, 'showPhoto'])->whereNumber('prescription')->name('photo.show');
});
