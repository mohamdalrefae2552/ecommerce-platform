<?php

declare(strict_types=1);

use App\Modules\Orders\Realtime\BroadcastOrderUpdate;
use App\Shared\Messaging\InvalidMessage;
use App\Shared\Messaging\Message;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;

/*
| Requirement 4: which channel receives which order event.
*/

beforeEach(function (): void {
    Event::fake([AnonymousEvent::class]);
});

/**
 * @return list<array{channels: list<string>, event: string, data: array<string, mixed>}>
 */
function broadcasts(): array
{
    $seen = [];

    Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use (&$seen): bool {
        /** @var list<Channel|string> $channels */
        $channels = Arr::wrap($event->broadcastOn());
        /** @var array<string, mixed> $data */
        $data = $event->broadcastWith();

        $seen[] = [
            'channels' => array_map(static fn (Channel|string $channel): string => (string) $channel, $channels),
            'event' => $event->broadcastAs(),
            'data' => $data,
        ];

        return true;
    });

    return $seen;
}

it('pushes a new order to the admin dashboard only, shaped like an OrderSummary', function (): void {
    app(BroadcastOrderUpdate::class)->handle(new Message('01JC43A1B2C3D4E5F6G7H8J9KA', 'order.placed', [
        'order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ',
        'user_id' => '01JC3Z8X7Q4M2N5P6R7S8T9V0W',
        'status' => 'pending',
        'total_minor' => 2500,
        'currency' => 'SYP',
        'item_count' => 2,
        'created_at' => '2026-11-13T10:00:00Z',
    ], '2026-11-13T10:00:00Z'));

    expect(broadcasts())->toBe([[
        'channels' => ['private-admin.orders'],
        'event' => 'order.placed',
        'data' => [
            'id' => '01JC41D4E5F6G7H8J9KAMBNCPQ',
            'user_id' => '01JC3Z8X7Q4M2N5P6R7S8T9V0W',
            'status' => 'pending',
            'total' => ['amount_minor' => 2500, 'currency' => 'SYP'],
            'item_count' => 2,
            'created_at' => '2026-11-13T10:00:00Z',
            'updated_at' => '2026-11-13T10:00:00Z',
        ],
    ]]);
});

it('pushes a status change to the order channel and to the admin dashboard, without the customer id', function (): void {
    app(BroadcastOrderUpdate::class)->handle(new Message('01JC43A1B2C3D4E5F6G7H8J9KB', 'order.status_changed', [
        'order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ',
        'user_id' => '01JC3Z8X7Q4M2N5P6R7S8T9V0W',
        'from' => 'pending',
        'to' => 'confirmed',
        'changed_at' => '2026-11-13T10:05:00Z',
    ], '2026-11-13T10:05:00Z'));

    $update = ['order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ', 'from' => 'pending', 'to' => 'confirmed', 'changed_at' => '2026-11-13T10:05:00Z'];

    expect(broadcasts())->toBe([
        ['channels' => ['private-orders.01JC41D4E5F6G7H8J9KAMBNCPQ'], 'event' => 'order.status_changed', 'data' => $update],
        ['channels' => ['private-admin.orders'], 'event' => 'order.status_changed', 'data' => $update],
    ]);
});

it('refuses a message it can never handle', function (): void {
    expect(fn () => app(BroadcastOrderUpdate::class)->handle(new Message('01JC43A1B2C3D4E5F6G7H8J9KC', 'order.exploded', [], '2026-11-13T10:00:00Z')))
        ->toThrow(InvalidMessage::class);

    Event::assertNotDispatched(AnonymousEvent::class);
});
