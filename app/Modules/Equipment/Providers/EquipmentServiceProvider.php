<?php

namespace App\Modules\Equipment\Providers;

use App\Modules\Equipment\Console\SendMaintenanceAlerts;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Policies\EquipmentPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class EquipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Equipment::class, EquipmentPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendMaintenanceAlerts::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('equipements:alertes-entretien')->dailyAt('07:00')->withoutOverlapping();
        });
    }
}
