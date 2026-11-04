<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $type order.placed | order.status_changed
 * @property string $order_id
 * @property string $order_status
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
final class UserNotification extends Model
{
    use HasUppercaseUlids;

    public const string ORDER_PLACED = 'order.placed';

    public const string ORDER_STATUS_CHANGED = 'order.status_changed';

    public const null UPDATED_AT = null;

    protected $table = 'notifications';

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'type', 'order_id', 'order_status'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'data' => ['order_id' => $this->order_id, 'status' => $this->order_status],
            'read_at' => $this->read_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
