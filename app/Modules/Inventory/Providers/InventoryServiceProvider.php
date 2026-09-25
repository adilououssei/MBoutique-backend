<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Policies\StockMovementPolicy;
use App\Modules\Inventory\Policies\StockPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Stock::class, StockPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);
    }
}
