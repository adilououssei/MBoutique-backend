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
| PUT actif=false instead (docs/cash-register.md §"Caisse inactive").
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:caisse')->prefix('caisses')->name('caisses.')->group(function () {
        Route::get('/', [CashRegisterController::class, 'index'])->name('index');
        Route::post('/', [CashRegisterController::class, 'store'])->name('store');
        Route::get('/{cashRegister}', [CashRegisterController::class, 'show'])->name('show');
        Route::put('/{cashRegister}', [CashRegisterController::class, 'update'])->name('update');

        Route::get('/{cashRegister}/session-courante', [CashRegisterSessionController::class, 'current'])->name('session-courante');
        Route::get('/{cashRegister}/sessions', [CashRegisterSessionController::class, 'index'])->name('sessions.index');
        Route::post('/{cashRegister}/sessions', [CashRegisterSessionController::class, 'open'])->name('sessions.ouvrir');
        Route::post('/{cashRegister}/sessions/{session}/fermer', [CashRegisterSessionController::class, 'close'])->name('sessions.fermer');

        Route::get('/{cashRegister}/sessions/{session}/mouvements', [CashMovementController::class, 'index'])->name('mouvements.index');
        Route::post('/{cashRegister}/sessions/{session}/entree', [CashMovementController::class, 'cashIn'])->name('mouvements.entree');
        Route::post('/{cashRegister}/sessions/{session}/sortie', [CashMovementController::class, 'cashOut'])->name('mouvements.sortie');
        Route::post('/{cashRegister}/sessions/{session}/ajustement', [CashMovementController::class, 'adjust'])->name('mouvements.ajustement');
    });
});
