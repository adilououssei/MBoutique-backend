<?php

use App\Modules\Auth\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Auth\Http\Controllers\CurrentUserController;
use App\Modules\Auth\Http\Controllers\NewPasswordController;
use App\Modules\Auth\Http\Controllers\PasswordController;
use App\Modules\Auth\Http\Controllers\PasswordResetLinkController;
use App\Modules\Auth\Http\Controllers\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [RegisteredUserController::class, 'store']);
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::get('me', [CurrentUserController::class, 'show']);
        Route::put('password', [PasswordController::class, 'update']);
    });
});
