<?php

use App\Modules\Admin\Http\Controllers\BusinessController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\DomainController;
use App\Modules\Admin\Http\Controllers\LoginController;
use App\Modules\Admin\Http\Controllers\PlanController;
use App\Modules\Admin\Http\Controllers\StoreController;
use App\Modules\Admin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Administration de la plateforme (Blade, session web)
|--------------------------------------------------------------------------
|
| Connexion à la racine du site. Tout le reste est sous /admin, réservé aux
| administrateurs de la plateforme (middleware admin.plateforme) — jamais
| mélangé avec les routes /api/boutiques/{store}/* des boutiquiers
| (docs/permissions.md §6).
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/', [LoginController::class, 'create'])->name('connexion');
    Route::post('/', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('connexion.store');
});

Route::post('/deconnexion', [LoginController::class, 'destroy'])->middleware('auth')->name('deconnexion');

Route::middleware(['auth', 'admin.plateforme'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('tableau-de-bord');

    Route::get('/entreprises', [BusinessController::class, 'index'])->name('entreprises.index');
    Route::get('/entreprises/{business}', [BusinessController::class, 'show'])->name('entreprises.show');
    Route::patch('/entreprises/{business}/statut', [BusinessController::class, 'updateStatus'])->name('entreprises.statut');
    Route::put('/entreprises/{business}/abonnement', [BusinessController::class, 'updateSubscription'])->name('entreprises.abonnement');

    Route::get('/boutiques', [StoreController::class, 'index'])->name('boutiques.index');
    Route::patch('/boutiques/{store}/statut', [StoreController::class, 'updateStatus'])->name('boutiques.statut');

    Route::get('/utilisateurs', [UserController::class, 'index'])->name('utilisateurs.index');
    Route::patch('/utilisateurs/{user}/statut', [UserController::class, 'updateStatus'])->name('utilisateurs.statut');

    Route::get('/forfaits', [PlanController::class, 'index'])->name('forfaits.index');
    Route::get('/forfaits/nouveau', [PlanController::class, 'create'])->name('forfaits.create');
    Route::post('/forfaits', [PlanController::class, 'store'])->name('forfaits.store');
    Route::get('/forfaits/{plan}/modifier', [PlanController::class, 'edit'])->name('forfaits.edit');
    Route::put('/forfaits/{plan}', [PlanController::class, 'update'])->name('forfaits.update');

    Route::get('/domaines', [DomainController::class, 'index'])->name('domaines.index');
    Route::get('/domaines/{domain}', [DomainController::class, 'show'])->name('domaines.show');
    Route::put('/domaines/{domain}', [DomainController::class, 'update'])->name('domaines.update');
});
