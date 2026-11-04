<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Domain\ReservationState;
use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reservation_id
 * @property string $user_id
 * @property string|null $idempotency_key
 * @property ReservationState $state
 * @property string|null $order_id
 * @property string|null $last_error
 */
final class ReservationAttempt extends Model
{
    use HasUppercaseUlids;

    protected $table = 'inventory_reservation_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = ['reservation_id', 'user_id', 'idempotency_key', 'state', 'order_id', 'last_error'];

    public function moveTo(ReservationState $state, ?string $error = null): void
    {
        $this->state = $state;
        $this->last_error = $error === null ? null : mb_substr($error, 0, 255);
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['state' => ReservationState::class];
    }
}
