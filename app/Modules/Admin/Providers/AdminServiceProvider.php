<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Console\CreatePlatformAdmin;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

/**
 * Interface d'administration de la plateforme (Blade, session web) —
 * docs/permissions.md §6. Routes : app/Modules/Admin/Routes/web.php (incluses
 * par routes/web.php), vues : resources/views/admin.
 */
class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Paginator::defaultView('admin.partials.pagination');

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformAdmin::class]);
        }
    }
}
