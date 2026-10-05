<?php

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
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

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:stock')->prefix('stocks')->name('stocks.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/{product}', [StockController::class, 'show'])->name('show');
        Route::put('/{product}', [StockController::class, 'update'])->name('update');
        Route::get('/{product}/mouvements', [StockMovementController::class, 'index'])->name('mouvements.index');
        Route::post('/{product}/mouvements', [StockMovementController::class, 'store'])->name('mouvements.store');
    });

    // Transferts entre boutiques de la même entreprise — docs/inventory.md §"Transferts".
    Route::middleware('feature:stock')->prefix('transferts')->name('transferts.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::get('/destinations', [StockTransferController::class, 'destinations'])->name('destinations');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer')->name('show');
    });
});
