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

    Route::get('stores', [StoreController::class, 'mine']);

    Route::prefix('businesses')->group(function () {
        Route::get('/', [BusinessController::class, 'index']);
        Route::post('/', [BusinessController::class, 'store']);

        Route::get('{business}', [BusinessController::class, 'show']);
        Route::post('{business}/users', [BusinessUserController::class, 'store']);

        Route::get('{business}/stores', [StoreController::class, 'index']);
        Route::post('{business}/stores', [StoreController::class, 'store']);
    });

    Route::prefix('stores/{store}')->middleware('store')->group(function () {
        Route::get('/', [StoreController::class, 'show']);

        Route::get('members', [StoreMemberController::class, 'index'])
            ->middleware('permission:store_users.view');
        Route::post('members', [StoreMemberController::class, 'store'])
            ->middleware('permission:store_users.manage');
        Route::delete('members/{user}', [StoreMemberController::class, 'destroy'])
            ->middleware('permission:store_users.manage');
    });
});
