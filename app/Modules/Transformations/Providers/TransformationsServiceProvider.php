<?php

namespace App\Modules\Transformations\Providers;

use App\Modules\Transformations\Models\PreparationSheet;
use App\Modules\Transformations\Models\Transformation;
use App\Modules\Transformations\Policies\PreparationSheetPolicy;
use App\Modules\Transformations\Policies\TransformationPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TransformationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
    }

    public function boot(): void
    {
        Gate::policy(Transformation::class, TransformationPolicy::class);
        Gate::policy(PreparationSheet::class, PreparationSheetPolicy::class);

        // Alias de référence des mouvements de stock d'une découpe ou d'une préparation.
        Relation::morphMap(['transformation' => Transformation::class]);
    }
}
