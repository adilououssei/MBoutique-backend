<?php

use App\Modules\Transformations\Http\Controllers\PreparationSheetController;
use App\Modules\Transformations\Http\Controllers\TransformationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Transformations routes
|--------------------------------------------------------------------------
|
| Même chaîne que les autres modules : auth:sanctum -> store -> feature ->
| Policy. Découpes et préparations partagent le contrôleur ; le type vient de
| la route. Pas de PUT/DELETE : l'historique est append-only, une erreur se
| corrige en annulant l'opération (stock remis en l'état).
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    foreach (['decoupes' => ['decoupe', 'decoupe'], 'preparations' => ['preparation', 'preparations']] as $path => [$type, $feature]) {
        Route::middleware("feature:{$feature}")->prefix($path)->name("{$path}.")->group(function () use ($type) {
            Route::get('/', [TransformationController::class, 'index'])->name('index')->defaults('type', $type);
            Route::post('/', [TransformationController::class, 'store'])->name('store')->defaults('type', $type);
            Route::get('/{transformation}', [TransformationController::class, 'show'])->whereNumber('transformation')->name('show')->defaults('type', $type);
            Route::post('/{transformation}/annuler', [TransformationController::class, 'cancel'])->whereNumber('transformation')->name('annuler')->defaults('type', $type);
        });
    }

    Route::middleware('feature:preparations')->group(function () {
        Route::apiResource('fiches-preparation', PreparationSheetController::class)->parameters(['fiches-preparation' => 'preparationSheet']);
    });
});
