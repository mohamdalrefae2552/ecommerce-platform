<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Contracts\Events\OrderPlaced;
use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Contracts\ProductCatalog;
use App\Shared\Auth\AuthUser;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creates an order (docs/05 section 3): idempotency first, then the
 * products are checked through the Products contract and their name and
 * price are copied into the lines, then everything is saved in one
 * transaction. OrderPlaced is dispatched after the commit.
 *
 * Idempotency-Key: the same key with the same body returns the order
 * already created (200); with a different body it is 422
 * IDEMPOTENCY_KEY_REUSED. A cache lock serialises concurrent requests with
 * one key and UNIQUE(user_id, idempotency_key) is the safety net.
 */
final readonly class PlaceOrder
{
    public function __construct(private ProductCatalog $catalog) {}

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

        return DB::transaction(function () use ($user, $lines, $key, $hash, $products): Order {
            $total = 0;
            foreach ($lines as $line) {
                $total += $products[$line['product_id']]->priceMinor * $line['quantity'];
            }

            $order = new Order(['total_minor' => $total, 'idempotency_key' => $key, 'request_hash' => $hash]);
            $order->user_id = $user->userId();
            $order->status = OrderStatus::Pending;
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

            event(new OrderPlaced($order->id, $order->user_id, $order->status->value));

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
