<?php

use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\CollectionController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProductImageController;
use App\Modules\Catalog\Http\Controllers\ProductImportController;
use App\Modules\Catalog\Http\Controllers\RecipeController;
use App\Modules\Catalog\Http\Controllers\ServiceController;
use App\Modules\Catalog\Http\Controllers\ServiceImageController;
use App\Modules\Catalog\Http\Controllers\VariantController;
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
        // Import Excel : réservé aux métiers qui ont la feature `import_produits`.
        Route::middleware('feature:import_produits')->group(function () {
            Route::get('produits/importer/modele', [ProductImportController::class, 'template'])->name('produits.importer.modele');
            Route::post('produits/importer', [ProductImportController::class, 'store'])->name('produits.importer');
        });
        Route::post('produits/{product}/image', [ProductImageController::class, 'store'])->name('produits.image.store');
        Route::delete('produits/{product}/image', [ProductImageController::class, 'destroy'])->name('produits.image.destroy');
        Route::get('produits/marques', [ProductController::class, 'brands'])->name('produits.marques');
        // Vêtements : variantes (taille / couleur) et collections.
        Route::middleware('feature:variantes')->group(function () {
            Route::get('produits/{product}/variantes', [VariantController::class, 'index'])->name('produits.variantes.index');
            Route::post('produits/{product}/variantes', [VariantController::class, 'store'])->name('produits.variantes.store');
        });
        Route::middleware('feature:collections')->group(function () {
            Route::apiResource('collections', CollectionController::class)->except(['show', 'destroy']);
        });
        // Restaurant : recette d'un plat (ingrédients déduits du stock à la vente).
        Route::middleware('feature:recettes')->group(function () {
            Route::get('recettes', [RecipeController::class, 'index'])->name('recettes.index');
            Route::get('produits/{product}/recette', [RecipeController::class, 'show'])->name('produits.recette.show');
            Route::put('produits/{product}/recette', [RecipeController::class, 'update'])->name('produits.recette.update');
            Route::delete('produits/{product}/recette', [RecipeController::class, 'destroy'])->name('produits.recette.destroy');
        });
        Route::apiResource('produits', ProductController::class)
            ->parameters(['produits' => 'product']);
    });

    Route::middleware('feature:services')->group(function () {
        Route::apiResource('services', ServiceController::class);
        Route::post('services/{service}/image', [ServiceImageController::class, 'store'])->name('services.image.store');
        Route::delete('services/{service}/image', [ServiceImageController::class, 'destroy'])->name('services.image.destroy');
    });
});
