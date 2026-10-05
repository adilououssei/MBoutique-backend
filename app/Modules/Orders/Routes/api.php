<?php

use App\Modules\Orders\Http\Controllers\DiningTableController;
use App\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders routes
|--------------------------------------------------------------------------
|
| auth:sanctum -> store -> feature -> Policy. `tables` dépend de
| `commandes` (FeatureDependency). scopeBindings() : une commande, une
| ligne ({item} via Order::items()) ou une table d'une autre boutique
| donne 404. Pas de DELETE de commande : on annule.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:commandes')->prefix('commandes')->name('commandes.')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('index');
        Route::post('/', [OrderController::class, 'store'])->name('store');
        Route::get('/{order}', [OrderController::class, 'show'])->name('show');
        Route::put('/{order}', [OrderController::class, 'update'])->name('update');
        Route::post('/{order}/lignes', [OrderController::class, 'addItems'])->name('lignes.store');
        Route::put('/{order}/lignes/{item}', [OrderController::class, 'updateItem'])->name('lignes.update');
        Route::delete('/{order}/lignes/{item}', [OrderController::class, 'removeItem'])->name('lignes.destroy');
        Route::post('/{order}/statut', [OrderController::class, 'changeStatus'])->name('statut');
        Route::post('/{order}/encaisser', [OrderController::class, 'checkout'])->name('encaisser');
        Route::post('/{order}/annuler', [OrderController::class, 'cancel'])->name('annuler');
    });

    Route::middleware('feature:tables')->prefix('tables')->name('tables.')->group(function () {
        Route::get('/', [DiningTableController::class, 'index'])->name('index');
        Route::post('/', [DiningTableController::class, 'store'])->name('store');
        Route::put('/{diningTable}', [DiningTableController::class, 'update'])->name('update');
    });
});
