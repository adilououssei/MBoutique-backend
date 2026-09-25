<?php

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

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('stores/{store}')->group(function () {
    Route::middleware('feature:sales')->prefix('sales')->name('sales.')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::post('/checkout', [SaleController::class, 'checkout'])->name('checkout');
        Route::get('/{sale}', [SaleController::class, 'show'])->name('show');
    });
});
