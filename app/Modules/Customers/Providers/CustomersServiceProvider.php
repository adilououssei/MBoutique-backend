<?php

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Policies\CustomerPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
    }
}
