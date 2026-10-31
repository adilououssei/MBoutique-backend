<?php

use App\Modules\Reports\Http\Controllers\DashboardReportController;
use App\Modules\Reports\Http\Controllers\ManagementReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reports routes
|--------------------------------------------------------------------------
|
| Read-only. auth:sanctum -> store (TenantContext) -> feature:rapports ->
| permission:rapports.voir. No Policy: there is no model instance to
| authorize against, only a store-level permission — same approach as
| the Tenancy member routes. See app/Modules/Reports/README.md.
|
*/

Route::middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}')->group(function () {
    Route::middleware(['feature:rapports', 'permission:rapports.voir'])->prefix('rapports')->name('rapports.')->group(function () {
        Route::get('/tableau-de-bord', [DashboardReportController::class, 'show'])->name('tableau-de-bord');
        // Rapport de gestion (boucherie) : feature distincte, le tableau de bord reste inchangé ailleurs.
        Route::get('/gestion', [ManagementReportController::class, 'show'])->middleware('feature:rapports_gestion')->name('gestion');
    });
});
