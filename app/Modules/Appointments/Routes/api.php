<?php

use App\Modules\Appointments\Http\Controllers\AppointmentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Appointments routes
|--------------------------------------------------------------------------
|
| auth:sanctum -> store -> feature:rendez_vous (qui dépend de services et
| employes, FeatureDependency) -> Policy. scopeBindings() : un rendez-vous
| d'une autre boutique donne 404. Pas de DELETE : on annule.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:rendez_vous')->prefix('rendez-vous')->name('rendez-vous.')->group(function () {
        Route::get('/', [AppointmentController::class, 'index'])->name('index');
        Route::post('/', [AppointmentController::class, 'store'])->name('store');
        Route::get('/{appointment}', [AppointmentController::class, 'show'])->name('show');
        Route::put('/{appointment}', [AppointmentController::class, 'update'])->name('update');
        Route::post('/{appointment}/statut', [AppointmentController::class, 'changeStatus'])->name('statut');
        Route::post('/{appointment}/annuler', [AppointmentController::class, 'cancel'])->name('annuler');
    });
});
