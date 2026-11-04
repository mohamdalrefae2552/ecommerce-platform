<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\ChangeOrderStatusController;
use App\Modules\Orders\Http\Controllers\CreateOrderController;
use App\Modules\Orders\Http\Controllers\ListOrdersController;
use App\Modules\Orders\Http\Controllers\ShowOrderController;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\Route;

/*
| Orders module routes, registered by OrdersServiceProvider under /api/v1
| with the "api" middleware group. Role checks run before the body is read.
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/orders', CreateOrderController::class)->middleware(['can:create,'.Order::class, 'throttle:orders-create']);
    Route::get('/orders', ListOrdersController::class);
    Route::get('/orders/{orderId}', ShowOrderController::class);
    Route::patch('/orders/{orderId}/status', ChangeOrderStatusController::class)->middleware('can:changeStatus,'.Order::class);
});
