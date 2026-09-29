<?php

use App\Modules\Tenancy\Http\Controllers\BusinessController;
use App\Modules\Tenancy\Http\Controllers\BusinessUserController;
use App\Modules\Tenancy\Http\Controllers\StoreController;
use App\Modules\Tenancy\Http\Controllers\StoreMemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenancy routes
|--------------------------------------------------------------------------
|
| Chain per docs/multi-tenancy.md / docs/security.md §16:
| auth:sanctum -> store (Couche 1, resolves {store} + membership,
| populates TenantContext) -> permission:* (Couche 4, spatie, store-scoped)
| -> controller -> Policy for anything not already covered by the
| middleware chain (BusinessPolicy has no middleware equivalent, so it's
| always an explicit $this->authorize() call in the controller).
|
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::get('boutiques', [StoreController::class, 'mine']);

    Route::prefix('entreprises')->group(function () {
        Route::get('/', [BusinessController::class, 'index']);
        Route::post('/', [BusinessController::class, 'store']);

        Route::get('{business}', [BusinessController::class, 'show']);
        Route::post('{business}/utilisateurs', [BusinessUserController::class, 'store']);

        Route::get('{business}/boutiques', [StoreController::class, 'index']);
        Route::post('{business}/boutiques', [StoreController::class, 'store']);
    });

    Route::prefix('boutiques/{store}')->middleware('store')->group(function () {
        Route::get('/', [StoreController::class, 'show']);

        Route::get('membres', [StoreMemberController::class, 'index'])
            ->middleware('permission:membres.voir');
        Route::post('membres', [StoreMemberController::class, 'store'])
            ->middleware('permission:membres.gerer');
        Route::delete('membres/{user}', [StoreMemberController::class, 'destroy'])
            ->middleware('permission:membres.gerer');
    });
});
