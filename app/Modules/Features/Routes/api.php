<?php

use App\Modules\Features\Http\Controllers\BusinessDomainController;
use App\Modules\Features\Http\Controllers\StoreFeatureController;
use App\Modules\Features\Http\Controllers\StoreFeatureSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('domaines-activite', [BusinessDomainController::class, 'index']);

Route::middleware(['auth:sanctum', 'store'])->group(function () {
    Route::get('boutiques/{store}/fonctionnalites', [StoreFeatureController::class, 'index']);
    Route::get('boutiques/{store}/parametres/fonctionnalites', [StoreFeatureSettingsController::class, 'index']);
    Route::put('boutiques/{store}/parametres/fonctionnalites', [StoreFeatureSettingsController::class, 'update']);
    Route::put('boutiques/{store}/parametres/specialite', [StoreFeatureSettingsController::class, 'updateSpecialty']);
    Route::put('boutiques/{store}/parametres/activite', [StoreFeatureSettingsController::class, 'updateActivity']);
});
