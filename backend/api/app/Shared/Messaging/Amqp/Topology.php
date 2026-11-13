<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Amqp;

use App\Shared\Messaging\QueueConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Declares the exchange and, for every consumer queue, three durable queues:
 *
 *   QUEUE        bound to the exchange; the worker reads it.
 *   QUEUE.retry  no consumer: a failed message waits here retry_delay_ms,
 *                then RabbitMQ dead-letters it back to QUEUE.
 *   QUEUE.dlq    messages that failed max_attempts times, kept for a human.
 *
 * Declarations are idempotent, so publishers and workers both run them and
 * neither depends on the other having started first.
 */
final readonly class Topology
{
    /**
     * @param  list<QueueConfig>  $queues
     */
    public function __construct(private string $exchange, private array $queues) {}

    public function exchange(): string
    {
        return $this->exchange;
    }

    public function declare(AMQPChannel $channel): void
    {
        $channel->exchange_declare($this->exchange, 'topic', false, true, false);

        foreach ($this->queues as $queue) {
            $channel->queue_declare($queue->name, false, true, false, false);
            $channel->queue_declare($queue->deadLetterQueue(), false, true, false, false);
            $channel->queue_declare($queue->retryQueue(), false, true, false, false, false, new AMQPTable([
                'x-message-ttl' => $queue->retryDelayMs,
                // Default exchange: the routing key is the queue name.
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $queue->name,
            ]));

            foreach ($queue->bindings as $routingKey) {
                $channel->queue_bind($queue->name, $this->exchange, $routingKey);
            }
        }
    }
}
