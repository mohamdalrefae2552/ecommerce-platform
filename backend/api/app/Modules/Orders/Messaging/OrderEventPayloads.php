<?php

declare(strict_types=1);

namespace App\Modules\Orders\Messaging;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Builds the payloads of the order events (see OrderMessages). Explicit
 * field lists: nothing leaves the module by accident.
 */
final class OrderEventPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function placed(Order $order, int $itemCount): array
    {
        return [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status->value,
            'total_minor' => $order->total_minor,
            'currency' => $order->currency,
            'item_count' => $itemCount,
            'created_at' => self::time($order->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusChanged(Order $order, OrderStatus $from): array
    {
        return [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'from' => $from->value,
            'to' => $order->status->value,
            'changed_at' => self::time($order->updated_at),
        ];
    }

    private static function time(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
