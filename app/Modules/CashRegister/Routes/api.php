<?php

use App\Modules\CashRegister\Http\Controllers\CashMovementController;
use App\Modules\CashRegister\Http\Controllers\CashRegisterController;
use App\Modules\CashRegister\Http\Controllers\CashRegisterSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CashRegister routes
|--------------------------------------------------------------------------
|
| Three levels of scoped binding: {store} -> {cashRegister} (via
| Store::cashRegisters()) -> {session} (via CashRegister::sessions()).
| No PUT/DELETE on a movement, ever — see docs/cash-register.md
| §"Immutabilité". No DELETE on a register either — deactivate via
| PUT is_active=false instead (docs/cash-register.md §"Caisse inactive").
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('stores/{store}')->group(function () {
    Route::middleware('feature:cash_register')->prefix('cash-registers')->name('cash-registers.')->group(function () {
        Route::get('/', [CashRegisterController::class, 'index'])->name('index');
        Route::post('/', [CashRegisterController::class, 'store'])->name('store');
        Route::get('/{cashRegister}', [CashRegisterController::class, 'show'])->name('show');
        Route::put('/{cashRegister}', [CashRegisterController::class, 'update'])->name('update');

        Route::get('/{cashRegister}/current-session', [CashRegisterSessionController::class, 'current'])->name('current-session');
        Route::get('/{cashRegister}/sessions', [CashRegisterSessionController::class, 'index'])->name('sessions.index');
        Route::post('/{cashRegister}/sessions', [CashRegisterSessionController::class, 'open'])->name('sessions.open');
        Route::post('/{cashRegister}/sessions/{session}/close', [CashRegisterSessionController::class, 'close'])->name('sessions.close');

        Route::get('/{cashRegister}/sessions/{session}/movements', [CashMovementController::class, 'index'])->name('movements.index');
        Route::post('/{cashRegister}/sessions/{session}/cash-in', [CashMovementController::class, 'cashIn'])->name('movements.cash-in');
        Route::post('/{cashRegister}/sessions/{session}/cash-out', [CashMovementController::class, 'cashOut'])->name('movements.cash-out');
        Route::post('/{cashRegister}/sessions/{session}/adjust', [CashMovementController::class, 'adjust'])->name('movements.adjust');
    });
});
