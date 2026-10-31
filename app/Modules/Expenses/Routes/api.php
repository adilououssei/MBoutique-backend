<?php

use App\Modules\Expenses\Http\Controllers\ExpenseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Expenses routes
|--------------------------------------------------------------------------
|
| auth:sanctum -> store -> feature -> Policy. Pas de PUT/DELETE : une
| dépense se corrige par une annulation (argent remis en caisse).
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:depenses')->prefix('depenses')->name('depenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->name('index');
        Route::get('/resume', [ExpenseController::class, 'summary'])->name('resume');
        Route::post('/', [ExpenseController::class, 'store'])->name('store');
        Route::get('/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->name('show');
        Route::post('/{expense}/annuler', [ExpenseController::class, 'cancel'])->whereNumber('expense')->name('annuler');
        Route::post('/{expense}/justificatif', [ExpenseController::class, 'uploadReceipt'])->whereNumber('expense')->name('justificatif.store');
        Route::get('/{expense}/justificatif', [ExpenseController::class, 'receipt'])->whereNumber('expense')->name('justificatif.show');
    });
});
