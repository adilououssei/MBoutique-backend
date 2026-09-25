<?php

use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\ProductController;
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
| under /stores/{storeA}/... 404s at routing time, before any Policy runs
| — see docs/catalog.md "Isolation" and Product::store()/Service::store()/
| Category::store() which this depends on.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('stores/{store}')->group(function () {
    Route::middleware('feature:categories')->group(function () {
        Route::apiResource('categories', CategoryController::class);
    });

    Route::middleware('feature:products')->group(function () {
        // Declared before the apiResource so they read clearly as their
        // own thing, even though "import"/"import/template" never
        // collide with the {product} wildcard routes below.
        Route::get('products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::post('products/import', [ProductImportController::class, 'store'])->name('products.import');
        Route::apiResource('products', ProductController::class);
    });

    Route::middleware('feature:services')->group(function () {
        Route::apiResource('services', ServiceController::class);
    });
});
