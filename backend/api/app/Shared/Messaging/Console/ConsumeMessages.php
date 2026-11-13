<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Console;

use App\Shared\Messaging\Amqp\AmqpConsumer;
use App\Shared\Messaging\MessageHandler;
use App\Shared\Messaging\QueueConfig;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Console\Command\SignalableCommandInterface;

/**
 * The independent worker (requirement 4): `php artisan messaging:consume
 * notifications.mail` runs in its own container. SIGTERM (docker stop)
 * lets the message in progress finish, then exits cleanly.
 */
final class ConsumeMessages extends Command implements SignalableCommandInterface
{
    protected $signature = 'messaging:consume {queue : A queue name from config/messaging.php}';

    protected $description = 'Consume one RabbitMQ queue until stopped';

    private ?AmqpConsumer $consumer = null;

    public function handle(AmqpConsumer $consumer, Container $container): int
    {
        $queue = QueueConfig::named((string) $this->argument('queue'));
        $handler = $container->make($queue->handler);

        if (! $handler instanceof MessageHandler) {
            $this->components->error($queue->handler.' is not a MessageHandler.');

            return self::FAILURE;
        }

        $this->consumer = $consumer;
        $consumer->consume($queue, $handler);

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->consumer?->stop();

        return false;
    }
}
