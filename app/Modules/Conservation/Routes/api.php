<?php

use App\Modules\Conservation\Http\Controllers\LossController;
use App\Modules\Conservation\Http\Controllers\StockLotController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Conservation routes
|--------------------------------------------------------------------------
|
| Même chaîne que les autres modules : auth:sanctum -> store -> feature ->
| Policy. Les lots se créent et se consomment seuls (mouvements de stock) :
| l'API ne permet que de les lire, corriger leur date et déclarer une perte.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:conservation')->group(function () {
        Route::get('lots', [StockLotController::class, 'index'])->name('lots.index');
        Route::get('lots/alertes', [StockLotController::class, 'alerts'])->name('lots.alertes');
        Route::put('lots/{stockLot}', [StockLotController::class, 'update'])->whereNumber('stockLot')->name('lots.update');
        Route::get('lots/peremptions', [StockLotController::class, 'expiries'])->name('lots.peremptions');
        Route::get('lots/{stockLot}', [StockLotController::class, 'show'])->whereNumber('stockLot')->name('lots.show');
        Route::post('lots/{stockLot}/statut', [StockLotController::class, 'changeStatus'])->whereNumber('stockLot')->name('lots.statut');
        Route::put('parametres/peremption', [StockLotController::class, 'updateThresholds'])->name('parametres.peremption');
        Route::post('lots/{stockLot}/perte', [StockLotController::class, 'loss'])->whereNumber('stockLot')->name('lots.perte');
        Route::get('pertes', [LossController::class, 'index'])->name('pertes.index');
    });
});
