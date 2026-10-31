<?php

namespace App\Modules\Devices\Providers;

use App\Modules\Devices\Models\Device;
use App\Modules\Devices\Policies\DevicePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class DevicesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Device::class, DevicePolicy::class);

        // Alias de référence des mouvements de stock d'un appareil (perte, remise en stock).
        Relation::morphMap(['appareil' => Device::class]);
    }
}
