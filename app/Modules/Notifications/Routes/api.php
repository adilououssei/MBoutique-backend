<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications routes
|--------------------------------------------------------------------------
|
| auth:sanctum -> store (membre de la boutique). Pas de feature : le centre
| de notifications existe pour toutes les boutiques ; chaque utilisateur ne
| voit que les siennes, pour la boutique de l'URL.
|
*/

Route::middleware(['auth:sanctum', 'store'])->prefix('boutiques/{store}/notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('/compteur', [NotificationController::class, 'count'])->name('compteur');
    Route::post('/tout-lire', [NotificationController::class, 'markAllRead'])->name('tout-lire');
    Route::post('/{notification}/lire', [NotificationController::class, 'markRead'])->name('lire');
});
