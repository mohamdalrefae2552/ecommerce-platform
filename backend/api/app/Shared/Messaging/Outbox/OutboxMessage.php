<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Outbox;

use App\Shared\Database\HasUppercaseUlids;
use App\Shared\Messaging\Message;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property Carbon|null $published_at
 */
final class OutboxMessage extends Model
{
    use HasUppercaseUlids;

    public const null UPDATED_AT = null;

    protected $table = 'outbox_messages';

    /**
     * @var list<string>
     */
    protected $fillable = ['type', 'payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array', 'published_at' => 'datetime'];
    }

    public function toMessage(): Message
    {
        return new Message($this->id, $this->type, $this->payload, $this->created_at->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
