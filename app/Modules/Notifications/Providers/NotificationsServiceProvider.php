<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Appointments\Events\AppointmentBooked;
use App\Modules\Inventory\Events\StockLevelChanged;
use App\Modules\Notifications\Console\SendAppointmentReminders;
use App\Modules\Notifications\Listeners\NotifyAppointmentBooked;
use App\Modules\Notifications\Listeners\NotifyLowStock;
use App\Modules\Notifications\Listeners\NotifyOrderReady;
use App\Modules\Orders\Events\OrderStatusChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Le module Notifications écoute les événements des autres modules : Stock,
 * Commandes et Rendez-vous ne le connaissent pas (aucune dépendance inverse).
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Event::listen(StockLevelChanged::class, NotifyLowStock::class);
        Event::listen(OrderStatusChanged::class, NotifyOrderReady::class);
        Event::listen(AppointmentBooked::class, NotifyAppointmentBooked::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendAppointmentReminders::class]);
        }

        // Nécessite le planificateur en production : `* * * * * php artisan schedule:run`
        // (ou `php artisan schedule:work` en développement).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('notifications:rappels-rendez-vous')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
