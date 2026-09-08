<?php

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Models\Business;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Policies\BusinessPolicy;
use App\Modules\Tenancy\Policies\StorePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Business::class, BusinessPolicy::class);
        Gate::policy(Store::class, StorePolicy::class);
    }
}
