<?php

use App\Modules\Features\Http\Controllers\StoreFeatureController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'store'])->group(function () {
    Route::get('stores/{store}/features', [StoreFeatureController::class, 'index']);
});
