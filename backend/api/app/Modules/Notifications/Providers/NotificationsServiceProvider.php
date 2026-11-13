<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Shared\Providers\ModuleServiceProvider;

/**
 * Notifications are produced by the `notifications.mail` queue worker
 * (Messaging\SendOrderNotification, wired in config/messaging.php).
 */
final class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
