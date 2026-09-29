<?php

use App\Modules\Auth\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Auth\Http\Controllers\CurrentUserController;
use App\Modules\Auth\Http\Controllers\NewPasswordController;
use App\Modules\Auth\Http\Controllers\PasswordController;
use App\Modules\Auth\Http\Controllers\PasswordResetLinkController;
use App\Modules\Auth\Http\Controllers\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('inscription', [RegisteredUserController::class, 'store']);
    Route::post('connexion', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::post('mot-de-passe-oublie', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1');
    Route::post('reinitialiser-mot-de-passe', [NewPasswordController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('deconnexion', [AuthenticatedSessionController::class, 'destroy']);
        Route::get('moi', [CurrentUserController::class, 'show']);
        Route::put('mot-de-passe', [PasswordController::class, 'update']);
    });
});
