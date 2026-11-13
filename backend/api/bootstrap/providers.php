<?php

declare(strict_types=1);

use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Products\Providers\ProductsServiceProvider;
use App\Modules\Users\Providers\UsersServiceProvider;
use App\Shared\Auth\AuthServiceProvider;
use App\Shared\Database\DatabaseServiceProvider;
use App\Shared\Inventory\InventoryServiceProvider;
use App\Shared\Messaging\MessagingServiceProvider;
use App\Shared\Realtime\RealtimeServiceProvider;

return [
    DatabaseServiceProvider::class,
    AuthServiceProvider::class,
    InventoryServiceProvider::class,
    MessagingServiceProvider::class,
    RealtimeServiceProvider::class,
    UsersServiceProvider::class,
    ProductsServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
];
