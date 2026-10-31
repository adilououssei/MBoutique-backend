<?php

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\DiningTablePolicy;
use App\Modules\Orders\Policies\OrderPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(DiningTable::class, DiningTablePolicy::class);

        // Alias de la référence des mouvements de caisse d'acompte.
        Relation::morphMap(['commande' => Order::class]);
    }
}
