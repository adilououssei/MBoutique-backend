<?php

namespace App\Modules\CashRegister\Providers;

use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Policies\CashMovementPolicy;
use App\Modules\CashRegister\Policies\CashRegisterPolicy;
use App\Modules\CashRegister\Policies\CashRegisterSessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CashRegisterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(CashRegister::class, CashRegisterPolicy::class);
        Gate::policy(CashRegisterSession::class, CashRegisterSessionPolicy::class);
        Gate::policy(CashMovement::class, CashMovementPolicy::class);
    }
}
