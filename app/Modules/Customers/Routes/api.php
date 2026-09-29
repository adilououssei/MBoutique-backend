<?php

use App\Modules\Customers\Http\Controllers\CustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers routes
|--------------------------------------------------------------------------
|
| Same chain as every other tenant-scoped module: auth:sanctum -> store
| (TenantContext) -> feature:clients -> Policy inside the controller.
| scopeBindings() gives {customer} the same "404, not a cross-store leak"
| guarantee as Product/Service/Category — see docs/customers.md.
|
*/

Route::scopeBindings()->middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware('feature:clients')->group(function () {
        Route::apiResource('clients', CustomerController::class)
            ->parameters(['clients' => 'customer']);
    });
});
