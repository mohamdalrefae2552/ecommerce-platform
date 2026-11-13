<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Amqp;

use App\Shared\Messaging\Message;
use App\Shared\Messaging\MessagePublisher;
use App\Shared\Messaging\PublishFailed;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Publishes persistent messages with publisher confirms: `publish` returns
 * only after RabbitMQ acknowledged the message, and fails if the broker
 * rejected it or could not route it to any queue. The connection is opened
 * on first use and reused for the lifetime of the process.
 */
final class AmqpPublisher implements MessagePublisher
{
    private ?AMQPChannel $channel = null;

    /** Messages the broker refused (nack) or could not route (return). */
    private int $rejections = 0;

    public function __construct(
        private readonly AmqpConnector $connector,
        private readonly Topology $topology,
        private readonly float $confirmTimeout,
    ) {}

    public function publish(Message $message): void
    {
        $rejectionsBefore = $this->rejections;

        try {
            $channel = $this->channel();

            $channel->basic_publish(self::encode($message), $this->topology->exchange(), $message->type, true);
            $channel->wait_for_pending_acks_returns($this->confirmTimeout);
        } catch (Throwable $e) {
            $this->channel = null;

            throw new PublishFailed('RabbitMQ did not accept message '.$message->id.': '.$e->getMessage(), previous: $e);
        }

        if ($this->rejections > $rejectionsBefore) {
            throw new PublishFailed('RabbitMQ rejected or could not route message '.$message->id.'.');
        }
    }

    private static function encode(Message $message): AMQPMessage
    {
        return new AMQPMessage(
            json_encode($message->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $message->id,
                'type' => $message->type,
                'timestamp' => (int) strtotime($message->occurredAt),
            ],
        );
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $channel = $this->connector->forPublishing()->channel();
        $this->topology->declare($channel);
        $channel->confirm_select();
        $channel->set_nack_handler(function (): void {
            $this->rejections++;
        });
        $channel->set_return_listener(function (): void {
            $this->rejections++;
        });

        return $this->channel = $channel;
    }
}
