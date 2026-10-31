<?php

namespace App\Modules\Returns\Providers;

use App\Modules\Returns\Models\SaleReturn;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        // Alias de référence des mouvements (stock, caisse, compte client) d'un retour.
        Relation::morphMap(['retour' => SaleReturn::class]);
    }
}
