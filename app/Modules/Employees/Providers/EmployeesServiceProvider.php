<?php

namespace App\Modules\Employees\Providers;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeePayment;
use App\Modules\Employees\Policies\EmployeePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class EmployeesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);

        // Alias de référence des mouvements de caisse créés par un paiement au personnel.
        Relation::morphMap(['paiement_employe' => EmployeePayment::class]);
    }
}
