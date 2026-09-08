<?php

namespace App\Modules\Features\Providers;

use Illuminate\Support\ServiceProvider;

class FeaturesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }
}
