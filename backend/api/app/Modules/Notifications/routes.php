<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\ListNotificationsController;
use App\Modules\Notifications\Http\Controllers\MarkNotificationReadController;
use Illuminate\Support\Facades\Route;

/*
| Notifications module routes, registered by NotificationsServiceProvider
| under /api/v1 with the "api" middleware group.
*/

Route::middleware('auth')->group(function (): void {
    Route::get('/notifications', ListNotificationsController::class);
    Route::patch('/notifications/{notificationId}/read', MarkNotificationReadController::class);
});
