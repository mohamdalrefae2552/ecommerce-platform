<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Contracts\Messages\OrderMessages;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ReservationState;
use App\Modules\Orders\Messaging\OrderEventPayloads;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\ReservationAttempt;
use App\Modules\Products\Contracts\CatalogProduct;
use App\Modules\Products\Contracts\ProductCatalog;
use App\Shared\Auth\AuthUser;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use App\Shared\Inventory\Exceptions\InsufficientStock;
use App\Shared\Inventory\InventoryGateway;
use App\Shared\Inventory\ReleaseReason;
use App\Shared\Inventory\ReservationLine;
use App\Shared\Messaging\Outbox\Outbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Creates an order with the inventory saga (docs/05 section 3, R2-F1,
 * R2-E1..E3):
 *
 * 1. Idempotency: the same key with the same body returns the order already
 *    created (200); with another body it is 422 IDEMPOTENCY_KEY_REUSED. A
 *    cache lock serialises one key; UNIQUE(user_id, idempotency_key) is the
 *    safety net.
 * 2. Products are checked through the Products contract and their name and
 *    price are copied into the lines.
 * 3. A fresh reservation id per attempt, journaled as PENDING and committed
 *    BEFORE ReserveStock, so a reservation is never lost.
 * 4. ONE ReserveStock call for every line (all or nothing).
 *    - insufficient ⇒ REJECTED, 409 with shortages, nothing to give back;
 *    - timeout / unavailable / unexpected answer ⇒ outcome unknown: release
 *      at once (RESERVE_OUTCOME_UNKNOWN), 503;
 * 5. The order is saved only after the inventory said yes. If saving fails,
 *    the units are released (ORDER_PERSISTENCE_FAILED) and the error is
 *    rethrown (500). A failed release leaves RELEASE_PENDING for the
 *    reconciler.
 * 6. The `order.placed` event is written to the outbox in the same
 *    transaction and published to RabbitMQ after the commit (requirement 4):
 *    the e-mail and the live updates never delay the answer.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private ProductCatalog $catalog,
        private InventoryGateway $inventory,
        private Outbox $outbox,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines
     */
    public function handle(AuthUser $user, array $lines, ?string $idempotencyKey): PlacedOrder
    {
        $hash = self::hash($lines);

        if ($idempotencyKey === null) {
            return new PlacedOrder($this->create($user, $lines, null, $hash), true);
        }

        $lock = Cache::lock('orders:idem:'.$user->userId().':'.$idempotencyKey, 10);

        $placed = $lock->block(5, function () use ($user, $lines, $idempotencyKey, $hash): PlacedOrder {
            $existing = $this->existing($user, $idempotencyKey, $hash);

            if ($existing !== null) {
                return new PlacedOrder($existing, false);
            }

            try {
                return new PlacedOrder($this->create($user, $lines, $idempotencyKey, $hash), true);
            } catch (UniqueConstraintViolationException) {
                $existing = $this->existing($user, $idempotencyKey, $hash);

                if ($existing === null) {
                    throw new ProblemException(ProblemCode::IdempotencyKeyReused);
                }

                return new PlacedOrder($existing, false);
            }
        });

        if (! $placed instanceof PlacedOrder) {
            throw new LogicException('The idempotency lock returned no order.');
        }

        return $placed;
    }

    private function existing(AuthUser $user, string $key, string $hash): ?Order
    {
        $order = Order::query()->where('user_id', $user->userId())->where('idempotency_key', $key)->first();

        if ($order !== null && ! hash_equals((string) $order->request_hash, $hash)) {
            throw new ProblemException(ProblemCode::IdempotencyKeyReused);
        }

        return $order;
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines
     */
    private function create(AuthUser $user, array $lines, ?string $key, string $hash): Order
    {
        $products = $this->products($lines);

        $attempt = ReservationAttempt::query()->create([
            'reservation_id' => (string) Str::uuid(),
            'user_id' => $user->userId(),
            'idempotency_key' => $key,
            'state' => ReservationState::Pending,
        ]);

        $this->reserve($attempt, $lines);

        try {
            return $this->save($user, $lines, $products, $key, $hash, $attempt);
        } catch (Throwable $e) {
            $this->release($attempt, ReleaseReason::OrderPersistenceFailed, $e);

            throw $e;
        }
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines
     * @return array<string, CatalogProduct>
     */
    private function products(array $lines): array
    {
        $products = $this->catalog->findMany(array_column($lines, 'product_id'));

        $missing = [];
        foreach ($lines as $index => $line) {
            if (! isset($products[$line['product_id']])) {
                $missing['items.'.$index.'.product_id'] = 'المنتج غير موجود.';
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        return $products;
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines
     */
    private function reserve(ReservationAttempt $attempt, array $lines): void
    {
        try {
            $this->inventory->reserve(
                $attempt->reservation_id,
                array_map(static fn (array $line): ReservationLine => new ReservationLine($line['product_id'], $line['quantity']), $lines),
            );
        } catch (InsufficientStock $e) {
            $attempt->moveTo(ReservationState::Rejected, 'insufficient stock');

            throw $e;
        } catch (Throwable $e) {
            // Timeout, unavailable or an unexpected answer: the units may or
            // may not be held, so give them back at once.
            $this->release($attempt, ReleaseReason::ReserveOutcomeUnknown, $e);

            throw $e;
        }
    }

    /**
     * Best-effort compensation that never hides the original error.
     */
    private function release(ReservationAttempt $attempt, ReleaseReason $reason, Throwable $cause): void
    {
        try {
            $this->inventory->release($attempt->reservation_id, $reason);
            $attempt->moveTo(ReservationState::Released, $cause::class);
        } catch (Throwable $releaseError) {
            report($releaseError);
            $attempt->moveTo(ReservationState::ReleasePending, $cause::class.' / '.$releaseError::class);
        }
    }

    /**
     * @param  list<array{product_id: string, quantity: int}>  $lines
     * @param  array<string, CatalogProduct>  $products
     */
    private function save(AuthUser $user, array $lines, array $products, ?string $key, string $hash, ReservationAttempt $attempt): Order
    {
        return DB::transaction(function () use ($user, $lines, $key, $hash, $products, $attempt): Order {
            $total = 0;
            foreach ($lines as $line) {
                $total += $products[$line['product_id']]->priceMinor * $line['quantity'];
            }

            $order = new Order(['total_minor' => $total, 'idempotency_key' => $key, 'request_hash' => $hash]);
            $order->user_id = $user->userId();
            $order->status = OrderStatus::Pending;
            $order->reservation_id = $attempt->reservation_id;
            $order->save();

            foreach ($lines as $line) {
                $product = $products[$line['product_id']];
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price_minor' => $product->priceMinor,
                    'quantity' => $line['quantity'],
                ]);
            }

            $order->history()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'actor_id' => $user->userId(),
                'actor_role' => $user->userRole(),
                'at' => Carbon::now(),
            ]);

            $attempt->order_id = $order->id;
            $attempt->moveTo(ReservationState::Confirmed);

            $this->outbox->record(OrderMessages::PLACED, OrderEventPayloads::placed($order, count($lines)));

            return $order;
        });
    }

    /**
     * Fingerprint of the request body, independent of the line order.
     *
     * @param  list<array{product_id: string, quantity: int}>  $lines
     */
    private static function hash(array $lines): string
    {
        usort($lines, static fn (array $a, array $b): int => strcmp($a['product_id'], $b['product_id']));

        return hash('sha256', (string) json_encode($lines));
    }
}
