<?php

namespace App\Modules\Appointments\Providers;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Policies\AppointmentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppointmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Appointment::class, AppointmentPolicy::class);
    }
}
