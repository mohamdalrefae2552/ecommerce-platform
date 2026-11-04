<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusChange;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Schemas Order (details) and OrderSummary (lists). Explicit field lists:
 * the actor id of the history is never exposed (API3).
 */
final class OrderPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function details(Order $order): array
    {
        return [
            'id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status->value,
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => self::money($item->unit_price_minor, $order->currency),
                'quantity' => $item->quantity,
                'line_total' => self::money($item->unit_price_minor * $item->quantity, $order->currency),
            ])->values()->all(),
            'total' => self::money($order->total_minor, $order->currency),
            'status_history' => $order->history->map(fn (OrderStatusChange $change): array => [
                'from' => $change->from_status?->value,
                'to' => $change->to_status->value,
                'actor_role' => $change->actor_role->value,
                'note' => $change->note,
                'at' => self::time($change->at),
            ])->values()->all(),
            'created_at' => self::time($order->created_at),
            'updated_at' => self::time($order->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Order $order, int $itemCount): array
    {
        return [
            'id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status->value,
            'total' => self::money($order->total_minor, $order->currency),
            'item_count' => $itemCount,
            'created_at' => self::time($order->created_at),
            'updated_at' => self::time($order->updated_at),
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    private static function money(int $amount, string $currency): array
    {
        return ['amount_minor' => $amount, 'currency' => $currency];
    }

    private static function time(DateTimeInterface $time): string
    {
        return Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
