<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Catalog\Policies\ProductPolicy;
use App\Modules\Catalog\Policies\ServicePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Service::class, ServicePolicy::class);

        // Registered now, before any polymorphic Sellable relation exists
        // (Sales/Orders, later phases), so historical rows are never at
        // risk of storing a full class name that later moves — see
        // docs/database.md §5 and docs/audit-2026-09.md.
        //
        // morphMap() (not enforceMorphMap()): the strict variant requires
        // EVERY morph relation in the whole app to be registered, which
        // broke Sanctum's own tokenable morph on User the moment it was
        // tried (ClassMorphViolationException on login) — found by the
        // Phase 3 test suite. morphMap() only aliases the two types that
        // actually need it, without demanding universal coverage.
        Relation::morphMap([
            'produit' => Product::class,
            'service' => Service::class,
        ]);
    }
}
