<?php

use App\Modules\Customers\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers routes
|--------------------------------------------------------------------------
|
| Same chain as every other tenant-scoped module: auth:sanctum -> store
| (TenantContext) -> feature:customers -> Policy inside the controller.
| scopeBindings() gives {customer} the same "404, not a cross-store leak"
| guarantee as Product/Service/Category — see docs/customers.md.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('stores/{store}')->group(function () {
    Route::middleware('feature:customers')->group(function () {
        Route::apiResource('customers', CustomerController::class);
    });
});
