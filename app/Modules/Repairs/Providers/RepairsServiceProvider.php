<?php

namespace App\Modules\Repairs\Providers;

use App\Modules\Repairs\Models\Repair;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class RepairsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        // Alias de référence des mouvements (pièces, paiements) d'une réparation.
        Relation::morphMap(['reparation' => Repair::class]);
    }
}
