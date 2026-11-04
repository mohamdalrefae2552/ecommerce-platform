<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Http\Requests\CreateOrderRequest;
use App\Modules\Orders\Http\Resources\OrderPresenter;
use App\Shared\Auth\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/orders (createOrder): customers only; 201 with Location, or
 * 200 with the same order for an idempotent replay.
 */
final readonly class CreateOrderController
{
    public function __construct(private PlaceOrder $place) {}

    public function __invoke(CreateOrderRequest $request): JsonResponse
    {
        $user = CurrentUser::of($request);

        $placed = $this->place->handle($user, $request->lines(), $request->idempotencyKey());
        $order = $placed->order->load(['items', 'history']);

        return new JsonResponse(
            ['data' => OrderPresenter::details($order)],
            $placed->created ? 201 : 200,
            ['Location' => url('/api/v1/orders/'.$order->id)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
