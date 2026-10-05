<?php

namespace App\Modules\Suppliers\Providers;

use App\Modules\Suppliers\Models\Purchase;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\Suppliers\Policies\PurchasePolicy;
use App\Modules\Suppliers\Policies\SupplierPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class SuppliersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Supplier::class, SupplierPolicy::class);
        Gate::policy(Purchase::class, PurchasePolicy::class);

        // Alias de référence des mouvements de stock et de caisse créés par un achat.
        Relation::morphMap(['achat' => Purchase::class]);
    }
}
