<?php

declare(strict_types=1);

namespace App\Modules\Orders\Realtime;

use App\Modules\Orders\Models\Order;
use App\Shared\Auth\AuthUser;

/**
 * The two private WebSocket channels of requirement 4 and who may subscribe
 * (checked by POST /api/v1/broadcasting/auth BEFORE the subscription):
 *
 *   orders.{orderId}  only the customer who owns the order;
 *   admin.orders      only an admin.
 */
final class OrderChannels
{
    public const string ORDER = 'orders.{orderId}';

    public const string ADMIN = 'admin.orders';

    public static function order(string $orderId): string
    {
        return 'orders.'.$orderId;
    }

    public static function mayFollowOrder(AuthUser $user, string $orderId): bool
    {
        return Order::query()->whereKey(strtoupper($orderId))->where('user_id', $user->userId())->exists();
    }

    public static function mayWatchAdminDashboard(AuthUser $user): bool
    {
        return $user->isAdmin();
    }
}
