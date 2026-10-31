<?php

use App\Modules\Repairs\Http\Controllers\QuoteController;
use App\Modules\Repairs\Http\Controllers\RepairController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:reparations')->prefix('reparations')->name('reparations.')->group(function () {
        Route::get('/', [RepairController::class, 'index'])->name('index');
        Route::get('/resume', [RepairController::class, 'summary'])->name('resume');
        Route::post('/', [RepairController::class, 'store'])->name('store');
        Route::get('/{repair}', [RepairController::class, 'show'])->whereNumber('repair')->name('show');
        Route::put('/{repair}', [RepairController::class, 'update'])->whereNumber('repair')->name('update');
        Route::post('/{repair}/statut', [RepairController::class, 'changeStatus'])->whereNumber('repair')->name('statut');
        Route::post('/{repair}/pieces', [RepairController::class, 'addPart'])->whereNumber('repair')->name('pieces.store');
        Route::delete('/{repair}/pieces/{part}', [RepairController::class, 'removePart'])->whereNumber('repair')->withoutScopedBindings()->name('pieces.destroy');
        Route::post('/{repair}/paiements', [RepairController::class, 'pay'])->whereNumber('repair')->name('paiements');
        // Atelier : prestations facturées et photos du bien confié.
        Route::post('/{repair}/prestations', [RepairController::class, 'addLine'])->whereNumber('repair')->name('prestations.store');
        Route::delete('/{repair}/prestations/{line}', [RepairController::class, 'removeLine'])->whereNumber('repair')->withoutScopedBindings()->name('prestations.destroy');
        Route::post('/{repair}/photos', [RepairController::class, 'addPhoto'])->whereNumber('repair')->name('photos.store');
        Route::delete('/{repair}/photos/{index}', [RepairController::class, 'removePhoto'])->whereNumber(['repair', 'index'])->withoutScopedBindings()->name('photos.destroy');
    });

    Route::middleware('feature:devis')->prefix('devis')->name('devis.')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])->name('index');
        Route::post('/', [QuoteController::class, 'store'])->name('store');
        Route::get('/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->name('show');
        Route::put('/{quote}', [QuoteController::class, 'update'])->whereNumber('quote')->name('update');
        Route::post('/{quote}/statut', [QuoteController::class, 'changeStatus'])->whereNumber('quote')->name('statut');
        Route::post('/{quote}/transformer', [QuoteController::class, 'convert'])->whereNumber('quote')->name('transformer');
    });
});
