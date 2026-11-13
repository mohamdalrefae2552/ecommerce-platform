<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

use App\Shared\Messaging\Amqp\AmqpConnector;
use App\Shared\Messaging\Amqp\AmqpConsumer;
use App\Shared\Messaging\Amqp\AmqpPublisher;
use App\Shared\Messaging\Amqp\Topology;
use App\Shared\Messaging\Console\ConsumeMessages;
use App\Shared\Messaging\Console\RelayOutbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Requirement 4 wiring: RabbitMQ publisher and consumer, the transactional
 * outbox and its relay (docs/10-async-realtime.md, ADR-0015).
 */
final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AmqpConnector::class, static fn (): AmqpConnector => new AmqpConnector(
            Config::string('messaging.amqp.host'),
            Config::integer('messaging.amqp.port'),
            Config::string('messaging.amqp.user'),
            Config::string('messaging.amqp.password'),
            Config::string('messaging.amqp.vhost'),
            Config::float('messaging.amqp.connect_timeout_seconds'),
            Config::float('messaging.amqp.io_timeout_seconds'),
        ));

        $this->app->singleton(Topology::class, static fn (): Topology => new Topology(Config::string('messaging.exchange'), QueueConfig::all()));

        $this->app->singleton(MessagePublisher::class, static fn (Application $app): AmqpPublisher => new AmqpPublisher(
            $app->make(AmqpConnector::class),
            $app->make(Topology::class),
            Config::float('messaging.amqp.confirm_timeout_seconds'),
        ));

        $this->app->bind(AmqpConsumer::class, static fn (Application $app): AmqpConsumer => new AmqpConsumer(
            $app->make(AmqpConnector::class),
            $app->make(Topology::class),
            $app->make(LoggerInterface::class),
            Config::float('messaging.amqp.confirm_timeout_seconds'),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->commands([ConsumeMessages::class, RelayOutbox::class]);

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command(RelayOutbox::class)->everyFiveSeconds()->withoutOverlapping(1);
        });
    }
}
