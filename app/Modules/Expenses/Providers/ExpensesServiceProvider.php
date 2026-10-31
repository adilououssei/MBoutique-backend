<?php

namespace App\Modules\Expenses\Providers;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Policies\ExpensePolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class ExpensesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Expense::class, ExpensePolicy::class);

        // Alias de référence des mouvements de caisse d'une dépense.
        Relation::morphMap(['depense' => Expense::class]);
    }
}
