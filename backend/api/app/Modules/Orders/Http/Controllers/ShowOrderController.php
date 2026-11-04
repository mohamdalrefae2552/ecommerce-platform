<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Resources\OrderPresenter;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/orders/{orderId} (getOrder): another customer's order is 404
 * exactly like a missing one (D-02, PROOF-R1-1).
 */
final readonly class ShowOrderController
{
    public function __invoke(string $orderId): JsonResponse
    {
        $order = Order::query()->with(['items', 'history'])->findOrFail(strtoupper($orderId));
        Gate::authorize('view', $order);

        return new JsonResponse(['data' => OrderPresenter::details($order)], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
