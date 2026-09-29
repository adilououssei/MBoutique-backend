<?php

use App\Modules\Features\Http\Controllers\BusinessDomainController;
use App\Modules\Features\Http\Controllers\StoreFeatureController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('domaines-activite', [BusinessDomainController::class, 'index']);

Route::middleware(['auth:sanctum', 'store'])->group(function () {
    Route::get('boutiques/{store}/fonctionnalites', [StoreFeatureController::class, 'index']);
});
