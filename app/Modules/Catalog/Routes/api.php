<?php

use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProductImageController;
use App\Modules\Catalog\Http\Controllers\ProductImportController;
use App\Modules\Catalog\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog routes
|--------------------------------------------------------------------------
|
| Chain: auth:sanctum -> store (Couche 1, TenantContext) -> feature:<slug>
| (FeatureGate, docs/feature-gate.md) -> Policy inside the controller
| (Couche 3 + spatie permission, docs/multi-tenancy.md). scopeBindings()
| gives {category}/{product}/{service} Couche 6 for free: a Store B id
| under /boutiques/{storeA}/... 404s at routing time, before any Policy runs
| — see docs/catalog.md "Isolation" and Product::store()/Service::store()/
| Category::store() which this depends on.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:categories')->group(function () {
        Route::apiResource('categories', CategoryController::class)
            ->parameters(['categories' => 'category']);
    });

    Route::middleware('feature:produits')->group(function () {
        // Declared before the apiResource so they read clearly as their
        // own thing, even though "importer"/"importer/modele" never
        // collide with the {product} wildcard routes below.
        Route::get('produits/importer/modele', [ProductImportController::class, 'template'])->name('produits.importer.modele');
        Route::post('produits/importer', [ProductImportController::class, 'store'])->name('produits.importer');
        Route::post('produits/{product}/image', [ProductImageController::class, 'store'])->name('produits.image.store');
        Route::delete('produits/{product}/image', [ProductImageController::class, 'destroy'])->name('produits.image.destroy');
        Route::apiResource('produits', ProductController::class)
            ->parameters(['produits' => 'product']);
    });

    Route::middleware('feature:services')->group(function () {
        Route::apiResource('services', ServiceController::class);
    });
});
