<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Contracts\Events\OrderStatusChanged;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Shared\Auth\AuthUser;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order to a new status (admin only, checked by OrderPolicy).
 * The row is locked so two concurrent changes cannot both pass the state
 * machine check; every change is written to order_status_history.
 */
final readonly class ChangeOrderStatus
{
    public function handle(string $orderId, OrderStatus $next, ?string $note, AuthUser $actor): Order
    {
        return DB::transaction(function () use ($orderId, $next, $note, $actor): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $current = $order->status;

            if (! $current->canTransitionTo($next)) {
                throw new ProblemException(
                    ProblemCode::OrderInvalidTransition,
                    'لا يمكن نقل الطلب من الحالة '.$current->value.' إلى '.$next->value.'.',
                );
            }

            $order->status = $next;
            $order->save();

            $order->history()->create([
                'from_status' => $current,
                'to_status' => $next,
                'actor_id' => $actor->userId(),
                'actor_role' => $actor->userRole(),
                'note' => $note,
                'at' => Carbon::now(),
            ]);

            event(new OrderStatusChanged($order->id, $order->user_id, $current->value, $next->value));

            return $order;
        });
    }
}
