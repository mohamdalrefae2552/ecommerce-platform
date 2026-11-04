<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Domain\OrderStatus;
use App\Shared\Auth\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row of order_status_history. The actor id is kept for audit but never
 * exposed (API3); only the actor's role is shown.
 *
 * @property int $id
 * @property string $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string $actor_id
 * @property Role $actor_role
 * @property string|null $note
 * @property Carbon $at
 */
final class OrderStatusChange extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_history';

    /**
     * @var list<string>
     */
    protected $fillable = ['from_status', 'to_status', 'actor_id', 'actor_role', 'note', 'at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'actor_role' => Role::class,
            'at' => 'datetime',
        ];
    }
}
