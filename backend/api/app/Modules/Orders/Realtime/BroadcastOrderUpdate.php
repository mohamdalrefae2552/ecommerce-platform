<?php

declare(strict_types=1);

namespace App\Modules\Orders\Realtime;

use App\Modules\Orders\Contracts\Messages\OrderMessages;
use App\Shared\Messaging\InvalidMessage;
use App\Shared\Messaging\Message;
use App\Shared\Messaging\MessageHandler;
use Illuminate\Support\Facades\Broadcast;

/**
 * Worker of the `realtime.orders` queue: pushes order events to the
 * WebSocket server (Reverb).
 *
 *   order.placed          -> admin.orders (the new order appears on the
 *                            admin dashboard without a refresh)
 *   order.status_changed  -> orders.{id} (the customer sees the new status
 *                            at once) and admin.orders (other admins too)
 *
 * A duplicate delivery only repeats a screen update, so no deduplication.
 */
final readonly class BroadcastOrderUpdate implements MessageHandler
{
    public function handle(Message $message): void
    {
        match ($message->type) {
            OrderMessages::PLACED => $this->placed($message),
            OrderMessages::STATUS_CHANGED => $this->statusChanged($message),
            default => throw new InvalidMessage('Unknown message type "'.$message->type.'".'),
        };
    }

    private function placed(Message $message): void
    {
        $totalMinor = $message->payload['total_minor'] ?? null;
        $itemCount = $message->payload['item_count'] ?? null;

        if (! is_int($totalMinor) || ! is_int($itemCount)) {
            throw new InvalidMessage('Message '.$message->id.' has no total or item count.');
        }

        // Same shape as OrderSummary in the REST contract.
        Broadcast::private(OrderChannels::ADMIN)->as(OrderMessages::PLACED)->with([
            'id' => $message->string('order_id'),
            'user_id' => $message->string('user_id'),
            'status' => $message->string('status'),
            'total' => ['amount_minor' => $totalMinor, 'currency' => $message->string('currency')],
            'item_count' => $itemCount,
            'created_at' => $message->string('created_at'),
            'updated_at' => $message->string('created_at'),
        ])->sendNow();
    }

    private function statusChanged(Message $message): void
    {
        $orderId = $message->string('order_id');
        $update = [
            'order_id' => $orderId,
            'from' => $message->string('from'),
            'to' => $message->string('to'),
            'changed_at' => $message->string('changed_at'),
        ];

        Broadcast::private(OrderChannels::order($orderId))->as(OrderMessages::STATUS_CHANGED)->with($update)->sendNow();
        Broadcast::private(OrderChannels::ADMIN)->as(OrderMessages::STATUS_CHANGED)->with($update)->sendNow();
    }
}
