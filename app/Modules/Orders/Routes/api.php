<?php

use App\Modules\Orders\Http\Controllers\DiningTableController;
use App\Modules\Orders\Http\Controllers\DropOffController;
use App\Modules\Orders\Http\Controllers\KitchenController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\ReservationController;
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
        Route::post('/{order}/acompte', [OrderController::class, 'deposit'])->name('acompte');
        Route::post('/{order}/encaisser', [OrderController::class, 'checkout'])->name('encaisser');
        Route::post('/{order}/annuler', [OrderController::class, 'cancel'])->name('annuler');
        // Restaurant : addition demandée, tables regroupées, addition divisée.
        Route::post('/{order}/addition', [OrderController::class, 'requestBill'])->name('addition');
        Route::post('/{order}/regrouper', [OrderController::class, 'merge'])->name('regrouper');
        Route::post('/{order}/diviser', [OrderController::class, 'splitCheckout'])->name('diviser');
        Route::post('/{order}/lignes/{item}/cuisine', [KitchenController::class, 'update'])->middleware('feature:cuisine')->name('lignes.cuisine');
    });

    // Pressing : remise des pièces, suivi pièce par pièce, recherche par code, réglage du solde avant remise.
    Route::middleware(['feature:commandes', 'feature:articles_depot'])->group(function () {
        Route::post('commandes/{order}/remise', [DropOffController::class, 'handover'])->name('commandes.remise');
        Route::put('commandes/{order}/articles/{article}', [DropOffController::class, 'updateArticle'])->name('commandes.articles.update');
        Route::post('commandes/{order}/articles/{article}/photo', [DropOffController::class, 'articlePhoto'])->name('commandes.articles.photo');
        Route::get('commandes/{order}/consommations', [DropOffController::class, 'consumption'])->name('commandes.consommations');
        Route::get('commandes-articles', [DropOffController::class, 'findArticle'])->name('commandes.articles.chercher');
        Route::get('parametres/pressing', [DropOffController::class, 'settings'])->name('pressing.parametres');
        Route::put('parametres/pressing', [DropOffController::class, 'updateSettings'])->name('pressing.parametres.update');
    });

    Route::middleware(['feature:commandes', 'feature:cuisine'])->get('cuisine', [KitchenController::class, 'index'])->name('cuisine.index');

    Route::middleware('feature:reservations')->prefix('reservations')->name('reservations.')->group(function () {
        Route::get('/', [ReservationController::class, 'index'])->name('index');
        Route::post('/', [ReservationController::class, 'store'])->name('store');
        Route::put('/{reservation}', [ReservationController::class, 'update'])->name('update');
    });

    Route::middleware('feature:tables')->prefix('tables')->name('tables.')->group(function () {
        Route::get('/', [DiningTableController::class, 'index'])->name('index');
        Route::post('/', [DiningTableController::class, 'store'])->name('store');
        Route::put('/{diningTable}', [DiningTableController::class, 'update'])->name('update');
    });
});
