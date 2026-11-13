<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Messaging;

use App\Modules\Notifications\Mail\OrderUpdateMail;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Contracts\Messages\OrderMessages;
use App\Modules\Users\Contracts\UserDirectory;
use App\Shared\Messaging\InvalidMessage;
use App\Shared\Messaging\Message;
use App\Shared\Messaging\MessageHandler;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Carbon;

/**
 * Worker of the `notifications.mail` queue (requirement 4): one in-app
 * notification and one e-mail per order event, outside the HTTP request.
 *
 * Idempotent: the notification row is keyed by the message id (unique), and
 * `mailed_at` records that the e-mail left. A redelivered message therefore
 * neither duplicates the notification nor sends a second e-mail. A mail
 * failure throws: the consumer retries, then parks the message in the DLQ.
 */
final readonly class SendOrderNotification implements MessageHandler
{
    public function __construct(private UserDirectory $users, private Mailer $mailer) {}

    public function handle(Message $message): void
    {
        $status = match ($message->type) {
            OrderMessages::PLACED => $message->string('status'),
            OrderMessages::STATUS_CHANGED => $message->string('to'),
            default => throw new InvalidMessage('Unknown message type "'.$message->type.'".'),
        };

        $notification = UserNotification::query()->createOrFirst(
            ['message_id' => $message->id],
            [
                'user_id' => $message->string('user_id'),
                'type' => $message->type,
                'order_id' => $message->string('order_id'),
                'order_status' => $status,
            ],
        );

        if ($notification->mailed_at !== null) {
            return;
        }

        $contact = $this->users->contact($notification->user_id);

        if ($contact !== null) {
            $this->mailer->to($contact->email)->send(new OrderUpdateMail(
                $contact->name,
                $notification->order_id,
                $status,
                $message->type === OrderMessages::PLACED,
            ));
        }

        $notification->mailed_at = Carbon::now();
        $notification->save();
    }
}
