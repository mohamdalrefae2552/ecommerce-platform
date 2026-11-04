<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\ChangeOrderStatus;
use App\Modules\Orders\Http\Requests\ChangeOrderStatusRequest;
use App\Modules\Orders\Http\Resources\OrderPresenter;
use App\Shared\Auth\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/v1/orders/{orderId}/status (changeOrderStatus): admin only,
 * enforced by the route middleware before anything else (PROOF-R1-2).
 */
final readonly class ChangeOrderStatusController
{
    public function __construct(private ChangeOrderStatus $change) {}

    public function __invoke(ChangeOrderStatusRequest $request, string $orderId): JsonResponse
    {
        $actor = CurrentUser::of($request);

        $order = $this->change->handle(strtoupper($orderId), $request->status(), $request->note(), $actor)
            ->load(['items', 'history']);

        return new JsonResponse(['data' => OrderPresenter::details($order)], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
