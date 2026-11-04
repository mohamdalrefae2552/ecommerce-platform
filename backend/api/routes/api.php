<?php

declare(strict_types=1);

use App\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Loaded under /api/v1 with the "api" middleware group (bootstrap/app.php).
// Module endpoints live in app/Modules/<Module>/routes.php.
Route::get('/health', HealthController::class)->name('health');
