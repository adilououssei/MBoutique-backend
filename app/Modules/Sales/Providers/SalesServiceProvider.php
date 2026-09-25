<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Policies\SalePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Sale::class, SalePolicy::class);

        // Sale is now a real `reference_type` on StockMovement/CashMovement
        // — registered here (not enforceMorphMap, see CatalogServiceProvider's
        // note on why) so those rows store 'sale', never the full class name.
        Relation::morphMap([
            'sale' => Sale::class,
        ]);
    }
}
