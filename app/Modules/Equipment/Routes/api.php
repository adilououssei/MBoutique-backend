<?php

use App\Modules\Equipment\Http\Controllers\EquipmentController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:equipements')->group(function () {
        Route::apiResource('equipements', EquipmentController::class)->parameters(['equipements' => 'equipment']);
        Route::post('equipements/{equipment}/interventions', [EquipmentController::class, 'intervene'])->name('equipements.interventions');
    });
});
