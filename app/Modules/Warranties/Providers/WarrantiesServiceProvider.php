<?php

namespace App\Modules\Warranties\Providers;

use App\Modules\Warranties\Models\Warranty;
use App\Modules\Warranties\Policies\WarrantyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class WarrantiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Warranty::class, WarrantyPolicy::class);
    }
}
