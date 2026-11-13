<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Outbox;

use App\Shared\Messaging\MessagePublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transactional outbox (requirement 4, ADR-0015).
 *
 * `record` is called INSIDE the business transaction: the event row commits
 * or rolls back together with the order, so an event can neither be lost
 * nor announce an order that does not exist. Right after the commit the row
 * is published to RabbitMQ; if the broker is down or slow the request still
 * answers, and `outbox:relay` (scheduler) publishes the row later.
 */
final readonly class Outbox
{
    public function __construct(private MessagePublisher $publisher, private LoggerInterface $log) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, array $payload): void
    {
        $row = OutboxMessage::query()->create(['type' => $type, 'payload' => $payload]);

        DB::afterCommit(function () use ($row): void {
            try {
                $this->publish($row);
            } catch (Throwable $e) {
                // Not an error for the user: the relay will deliver it.
                $this->log->warning('outbox: publish deferred to the relay', ['message_id' => $row->id, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Publishes the rows the requests could not publish themselves, oldest
     * first. Rows are locked (SKIP LOCKED) so two relays never send the same
     * row at the same time. Returns the number of rows published.
     */
    public function relay(int $olderThanSeconds, int $batch): int
    {
        return DB::transaction(function () use ($olderThanSeconds, $batch): int {
            $rows = OutboxMessage::query()
                ->whereNull('published_at')
                ->where('created_at', '<=', Carbon::now()->subSeconds($olderThanSeconds))
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($batch)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($rows as $row) {
                $this->publish($row);
            }

            return $rows->count();
        });
    }

    public function prune(int $keepHours): int
    {
        $deleted = OutboxMessage::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<', Carbon::now()->subHours($keepHours))
            ->delete();

        return is_int($deleted) ? $deleted : 0;
    }

    private function publish(OutboxMessage $row): void
    {
        $this->publisher->publish($row->toMessage());

        // Guarded update: harmless if the relay and a request overlap (the
        // message is then sent twice; consumers are idempotent).
        OutboxMessage::query()->whereKey($row->id)->whereNull('published_at')->update(['published_at' => Carbon::now()]);
    }
}
