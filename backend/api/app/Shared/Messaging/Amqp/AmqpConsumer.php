<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Amqp;

use App\Shared\Messaging\InvalidMessage;
use App\Shared\Messaging\Message;
use App\Shared\Messaging\MessageHandler;
use App\Shared\Messaging\QueueConfig;
use Illuminate\Support\Carbon;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Worker loop of one queue (requirement 4: no message is ever lost).
 *
 * - Manual acknowledgement, one message at a time: a message leaves the
 *   queue only after it was handled, so a worker that is stopped or crashes
 *   mid-message gets it again when it restarts.
 * - A failed message is copied to the retry queue (it comes back after the
 *   delay) with its attempt count; after max_attempts it is copied to the
 *   dead-letter queue with the error. The copy is confirmed by the broker
 *   BEFORE the original is acknowledged.
 */
final class AmqpConsumer
{
    public const string HEADER_ATTEMPTS = 'x-attempts';

    private bool $stopping = false;

    public function __construct(
        private readonly AmqpConnector $connector,
        private readonly Topology $topology,
        private readonly LoggerInterface $log,
        private readonly float $confirmTimeout,
    ) {}

    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Blocks until stop() is called (SIGTERM / SIGINT) or the connection is
     * lost (the exception ends the process; the container restarts it).
     */
    public function consume(QueueConfig $queue, MessageHandler $handler): void
    {
        $connection = $this->connector->forConsuming();
        $channel = $connection->channel();
        $this->topology->declare($channel);
        $channel->confirm_select();
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume(
            $queue->name,
            callback: function (AMQPMessage $delivery) use ($channel, $queue, $handler): void {
                $this->process($channel, $queue, $handler, $delivery);
            },
        );

        $this->log->info('worker started', ['queue' => $queue->name]);

        while (! $this->stopping && $channel->is_consuming()) {
            try {
                $channel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException) {
                // Nothing arrived within a second: check for a stop signal.
            }
        }

        $channel->close();
        $connection->close();
        $this->log->info('worker stopped', ['queue' => $queue->name]);
    }

    private function process(AMQPChannel $channel, QueueConfig $queue, MessageHandler $handler, AMQPMessage $delivery): void
    {
        $attempt = self::attempts($delivery) + 1;
        $context = ['queue' => $queue->name, 'message_id' => $delivery->get_properties()['message_id'] ?? null, 'attempt' => $attempt];

        try {
            $handler->handle(self::decode($delivery));
            $this->log->info('message handled', $context);
        } catch (InvalidMessage $e) {
            $this->log->error('message parked in the dead-letter queue: invalid', $context + ['error' => $e->getMessage()]);
            $this->forward($channel, $delivery, $queue->deadLetterQueue(), $attempt, $e);
        } catch (Throwable $e) {
            if ($attempt >= $queue->maxAttempts) {
                $this->log->error('message parked in the dead-letter queue: attempts exhausted', $context + ['error' => $e->getMessage()]);
                $this->forward($channel, $delivery, $queue->deadLetterQueue(), $attempt, $e);
            } else {
                $this->log->warning('message failed, it will be retried', $context + ['error' => $e->getMessage()]);
                $this->forward($channel, $delivery, $queue->retryQueue(), $attempt, $e);
            }
        }

        $delivery->ack();
    }

    /**
     * Copies the message to another queue through the default exchange and
     * waits for the confirmation of the broker. If that fails the exception
     * ends the worker WITHOUT acknowledging: the original is redelivered.
     */
    private function forward(AMQPChannel $channel, AMQPMessage $delivery, string $toQueue, int $attempt, Throwable $error): void
    {
        $properties = $delivery->get_properties();
        $properties['application_headers'] = new AMQPTable([
            self::HEADER_ATTEMPTS => $attempt,
            'x-last-error' => mb_substr($error::class.': '.$error->getMessage(), 0, 500),
            'x-failed-at' => Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        $channel->basic_publish(new AMQPMessage($delivery->getBody(), $properties), '', $toQueue, true);
        $channel->wait_for_pending_acks_returns($this->confirmTimeout);
    }

    private static function attempts(AMQPMessage $delivery): int
    {
        $headers = $delivery->get_properties()['application_headers'] ?? null;
        $attempts = $headers instanceof AMQPTable ? ($headers->getNativeData()[self::HEADER_ATTEMPTS] ?? 0) : 0;

        return is_int($attempts) ? $attempts : 0;
    }

    private static function decode(AMQPMessage $delivery): Message
    {
        $properties = $delivery->get_properties();
        $id = $properties['message_id'] ?? null;
        $type = $properties['type'] ?? null;

        try {
            $payload = json_decode($delivery->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidMessage('The message body is not JSON: '.$e->getMessage());
        }

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '' || ! is_array($payload) || array_is_list($payload)) {
            throw new InvalidMessage('The message has no id, no type or no JSON object body.');
        }

        $timestamp = $properties['timestamp'] ?? null;

        /** @var array<string, mixed> $payload */
        return new Message($id, $type, $payload, Carbon::createFromTimestampUTC(is_int($timestamp) ? $timestamp : Carbon::now()->getTimestamp())->format('Y-m-d\TH:i:s\Z'));
    }
}
