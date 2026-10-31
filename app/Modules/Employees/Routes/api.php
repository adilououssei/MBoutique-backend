<?php

use App\Modules\Employees\Http\Controllers\EmployeeActivityController;
use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\EmployeePlanningController;
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
        // Planning (salon) : prestations habilitées, horaires, congés et absences.
        Route::get('employes/{employee}/activite', [EmployeeActivityController::class, 'show'])->name('employes.activite');
        Route::post('employes/{employee}/photo', [EmployeeActivityController::class, 'storePhoto'])->name('employes.photo.store');
        Route::delete('employes/{employee}/photo', [EmployeeActivityController::class, 'destroyPhoto'])->name('employes.photo.destroy');
        Route::get('employes/{employee}/planning', [EmployeePlanningController::class, 'show'])->name('employes.planning.show');
        Route::put('employes/{employee}/planning', [EmployeePlanningController::class, 'update'])->name('employes.planning.update');
        Route::post('employes/{employee}/absences', [EmployeePlanningController::class, 'storeAbsence'])->name('employes.absences.store');
        Route::delete('employes/{employee}/absences/{absence}', [EmployeePlanningController::class, 'destroyAbsence'])->name('employes.absences.destroy');
    });
});
