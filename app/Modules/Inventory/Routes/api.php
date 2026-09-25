<?php

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory routes
|--------------------------------------------------------------------------
|
| Keyed by {product}, not a Stock id — a merchant thinks in terms of "the
| stock of this product", and {product} already gets the same scoped
| route binding as everywhere else in Catalog (Store::products()), so no
| Stock-specific binding is needed. No PUT/DELETE on a movement, ever —
| see docs/inventory.md §"Immutabilité".
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('stores/{store}')->group(function () {
    Route::middleware('feature:inventory')->prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/{product}', [StockController::class, 'show'])->name('show');
        Route::put('/{product}', [StockController::class, 'update'])->name('update');
        Route::get('/{product}/movements', [StockMovementController::class, 'index'])->name('movements.index');
        Route::post('/{product}/movements', [StockMovementController::class, 'store'])->name('movements.store');
    });
});
