<?php

use App\Modules\Employees\Http\Controllers\EmployeeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Employees routes
|--------------------------------------------------------------------------
|
| auth:sanctum -> store -> feature:employes -> Policy. scopeBindings() :
| un employé d'une autre boutique donne 404 (Store::employees()). Les
| paiements sont append-only (pas de PUT/DELETE), comme les autres ledgers.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:employes')->group(function () {
        Route::apiResource('employes', EmployeeController::class)->parameters(['employes' => 'employee']);
        Route::get('employes/{employee}/paiements', [EmployeeController::class, 'payments'])->name('employes.paiements.index');
        Route::post('employes/{employee}/paiements', [EmployeeController::class, 'pay'])->name('employes.paiements.store');
    });
});
