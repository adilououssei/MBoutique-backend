<?php

namespace App\Modules\Conservation\Providers;

use App\Modules\Conservation\Console\SendExpiryAlerts;
use App\Modules\Conservation\Listeners\TrackStockLots;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Conservation\Policies\StockLotPolicy;
use App\Modules\Conservation\Services\LotService;
use App\Modules\Inventory\Events\StockMovementRecorded;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ConservationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        // Une seule instance : la vente désigne le lot, l'écouteur du mouvement le consomme.
        $this->app->singleton(LotService::class);
    }

    public function boot(): void
    {
        Gate::policy(StockLot::class, StockLotPolicy::class);
        Event::listen(StockMovementRecorded::class, TrackStockLots::class);

        // Alias de référence du mouvement `perte` d'un lot précis.
        Relation::morphMap(['lot_stock' => StockLot::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([SendExpiryAlerts::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('conservation:alertes-peremption')->dailyAt('07:00')->withoutOverlapping();
        });
    }
}
