<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Catalog\Contracts\InitialStockRecorder;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Policies\StockMovementPolicy;
use App\Modules\Inventory\Policies\StockPolicy;
use App\Modules\Inventory\Services\InventoryInitialStockRecorder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        // Contrat de Catalog (stock initial à l'import Excel), implémenté ici.
        $this->app->bind(InitialStockRecorder::class, InventoryInitialStockRecorder::class);
    }

    public function boot(): void
    {
        Gate::policy(Stock::class, StockPolicy::class);
        Gate::policy(StockMovement::class, StockMovementPolicy::class);

        // Référence des mouvements de stock d'un transfert entre boutiques.
        Relation::morphMap(['transfert_stock' => StockTransfer::class]);
    }
}
