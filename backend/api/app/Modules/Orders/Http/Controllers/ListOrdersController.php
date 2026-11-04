<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Http\Requests\ListOrdersRequest;
use App\Modules\Orders\Http\Resources\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Shared\Auth\CurrentUser;
use App\Shared\Http\Pagination\PageEnvelope;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/orders (listOrders): a customer's query is restricted to
 * their own orders in SQL (never filtered after loading); the admin sees
 * every order and may filter by status and user.
 */
final readonly class ListOrdersController
{
    public function __invoke(ListOrdersRequest $request): JsonResponse
    {
        $user = CurrentUser::of($request);

        $status = $request->status();
        $owner = $user->isAdmin() ? $request->userFilter() : $user->userId();

        $page = Order::query()
            ->withCount('items')
            ->when($owner !== null, fn ($query) => $query->where('user_id', $owner))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        /** @var list<Order> $orders */
        $orders = array_values($page->items());

        return PageEnvelope::response(
            $request,
            $page,
            array_map(static fn (Order $order): array => OrderPresenter::summary($order, $order->items_count ?? 0), $orders),
        );
    }
}
