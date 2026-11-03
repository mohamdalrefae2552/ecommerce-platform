<?php

declare(strict_types=1);

use App\Modules\Users\Http\Controllers\LoginController;
use App\Modules\Users\Http\Controllers\LogoutController;
use App\Modules\Users\Http\Controllers\MeController;
use App\Modules\Users\Http\Controllers\RefreshController;
use App\Modules\Users\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

/*
| Users module routes, registered by UsersServiceProvider under /api/v1 with
| the "api" middleware group. Rate limiters: docs/04 section 3.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/register', RegisterController::class)->middleware('throttle:register');
    Route::post('/login', LoginController::class)->middleware('throttle:login');
    Route::post('/refresh', RefreshController::class)->middleware('throttle:refresh');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', MeController::class);
    });
});
