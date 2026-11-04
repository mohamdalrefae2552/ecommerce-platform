<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Database\Factories\OrderFactory;
use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property OrderStatus $status
 * @property int $total_minor
 * @property string $currency
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property string|null $reservation_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Collection<int, OrderItem> $items
 * @property Collection<int, OrderStatusChange> $history
 * @property-read int|null $items_count only with withCount('items')
 */
#[UseFactory(OrderFactory::class)]
final class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUppercaseUlids;

    /**
     * Owner and status are set explicitly by the actions, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['total_minor', 'idempotency_key', 'request_hash'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['currency' => 'SYP'];

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'total_minor' => 'integer'];
    }
}
