<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Listeners;

use App\Modules\Notifications\Mail\OrderUpdateMail;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Contracts\Events\OrderPlaced;
use App\Modules\Orders\Contracts\Events\OrderStatusChanged;
use App\Modules\Users\Contracts\UserDirectory;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notifies the customer of an order event: one database notification and
 * one e-mail. Runs synchronously after the order transaction commits (the
 * events are ShouldDispatchAfterCommit); requirement 4 moves it to a queue.
 * A mail failure is reported but never undoes the order.
 */
final readonly class NotifyCustomer
{
    public function __construct(private UserDirectory $users) {}

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->notify($event->userId, UserNotification::ORDER_PLACED, $event->orderId, $event->status);
    }

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        $this->notify($event->userId, UserNotification::ORDER_STATUS_CHANGED, $event->orderId, $event->to);
    }

    private function notify(string $userId, string $type, string $orderId, string $status): void
    {
        UserNotification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'order_id' => $orderId,
            'order_status' => $status,
        ]);

        $contact = $this->users->contact($userId);

        if ($contact === null) {
            return;
        }

        try {
            Mail::to($contact->email)->send(new OrderUpdateMail($contact->name, $orderId, $status, $type === UserNotification::ORDER_PLACED));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
