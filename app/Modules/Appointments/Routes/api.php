<?php

use App\Modules\Appointments\Http\Controllers\AppointmentController;
use App\Modules\Appointments\Http\Controllers\PackageController;
use App\Modules\Appointments\Http\Controllers\SalonController;
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
        Route::get('/creneaux', [AppointmentController::class, 'freeSlots'])->name('creneaux');
        Route::post('/', [AppointmentController::class, 'store'])->name('store');
        Route::get('/{appointment}', [AppointmentController::class, 'show'])->name('show');
        Route::put('/{appointment}', [AppointmentController::class, 'update'])->name('update');
        Route::post('/{appointment}/statut', [AppointmentController::class, 'changeStatus'])->name('statut');
        Route::post('/{appointment}/annuler', [AppointmentController::class, 'cancel'])->name('annuler');
        Route::post('/{appointment}/encaisser', [AppointmentController::class, 'checkout'])->name('encaisser');
        Route::get('/{appointment}/consommations', [AppointmentController::class, 'consumption'])->name('consommations');
    });

    // Salon de beauté : réglages (horaires, fidélité), forfaits, commissions, fidélité client.
    Route::middleware('feature:rendez_vous')->group(function () {
        Route::get('parametres/salon', [SalonController::class, 'settings'])->name('salon.parametres');
        Route::put('parametres/salon', [SalonController::class, 'updateSettings'])->name('salon.parametres.update');
    });
    Route::middleware('feature:forfaits')->group(function () {
        Route::get('forfaits', [PackageController::class, 'index'])->name('forfaits.index');
        Route::post('forfaits', [PackageController::class, 'store'])->name('forfaits.store');
        Route::put('forfaits/{package}', [PackageController::class, 'update'])->name('forfaits.update');
        Route::delete('forfaits/{package}', [PackageController::class, 'destroy'])->name('forfaits.destroy');
        Route::post('forfaits/{package}/vendre', [PackageController::class, 'sell'])->name('forfaits.vendre');
        Route::get('forfaits-clients', [PackageController::class, 'customerPackages'])->name('forfaits.clients');
    });
    Route::middleware('feature:commissions')->group(function () {
        Route::get('commissions', [SalonController::class, 'commissions'])->name('commissions.index');
        Route::post('commissions/payer', [SalonController::class, 'payCommissions'])->name('commissions.payer');
    });
    Route::middleware('feature:fidelite')->group(function () {
        Route::get('clients/{customer}/fidelite', [SalonController::class, 'loyalty'])->name('clients.fidelite');
        Route::post('clients/{customer}/fidelite/ajustement', [SalonController::class, 'adjustLoyalty'])->name('clients.fidelite.ajustement');
    });
});
