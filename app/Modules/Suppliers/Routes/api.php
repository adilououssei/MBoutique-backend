<?php

use App\Modules\Suppliers\Http\Controllers\PurchaseController;
use App\Modules\Suppliers\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Suppliers routes
|--------------------------------------------------------------------------
|
| Même chaîne que les autres modules : auth:sanctum -> store -> feature ->
| Policy. scopeBindings() : un fournisseur ou un achat d'une autre boutique
| donne 404 (Store::suppliers()/purchases()). Pas de PUT/DELETE sur un
| achat : l'historique d'achats est append-only, comme les ventes.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:fournisseurs')->group(function () {
        Route::apiResource('fournisseurs', SupplierController::class)->parameters(['fournisseurs' => 'supplier']);

        Route::prefix('achats')->name('achats.')->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])->name('index');
            Route::post('/', [PurchaseController::class, 'store'])->name('store');
            Route::get('/{purchase}', [PurchaseController::class, 'show'])->name('show');
            Route::post('/{purchase}/paiements', [PurchaseController::class, 'pay'])->name('paiements');
        });
    });
});
