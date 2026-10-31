<?php

use App\Modules\Sales\Http\Controllers\PromotionController;
use App\Modules\Sales\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales routes
|--------------------------------------------------------------------------
|
| No cart routes — the cart isn't a persisted server-side resource in
| this phase, see docs/sales.md §"Panier". checkout() is the only write
| endpoint; no PUT/DELETE on a Sale anywhere (append-only, same spirit
| as Inventory/CashRegister's ledgers).
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:ventes')->prefix('ventes')->name('ventes.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::post('/encaisser', [SaleController::class, 'checkout'])->name('encaisser');
        Route::get('/{sale}', [SaleController::class, 'show'])->name('show');
        Route::post('/{sale}/annuler', [SaleController::class, 'cancel'])->name('annuler');
    });
});

Route::scopeBindings()->middleware(['auth:sanctum', 'store', 'feature:promotions'])->prefix('boutiques/{store}/promotions')->name('promotions.')->group(function () {
    Route::get('/', [PromotionController::class, 'index'])->name('index');
    Route::post('/', [PromotionController::class, 'store'])->name('store');
    Route::get('/{promotion}', [PromotionController::class, 'show'])->name('show');
    Route::put('/{promotion}', [PromotionController::class, 'update'])->name('update');
});
