<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Console;

use App\Shared\Messaging\Outbox\Outbox;
use App\Shared\Messaging\PublishFailed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Publishes outbox rows that are still waiting (RabbitMQ was down or slow
 * when the request finished) and prunes old published rows. Scheduled every
 * five seconds by MessagingServiceProvider.
 */
final class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay';

    protected $description = 'Publish pending outbox messages to RabbitMQ';

    public function handle(Outbox $outbox): int
    {
        try {
            $published = $outbox->relay(
                Config::integer('messaging.outbox.relay_after_seconds'),
                Config::integer('messaging.outbox.relay_batch'),
            );
        } catch (PublishFailed $e) {
            // The rows stay pending; the next run tries again.
            $this->components->warn('RabbitMQ is not reachable: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($published > 0) {
            $this->components->info($published.' pending message(s) published.');
        }

        $outbox->prune(Config::integer('messaging.outbox.keep_published_hours'));

        return self::SUCCESS;
    }
}
