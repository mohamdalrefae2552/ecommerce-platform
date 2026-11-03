<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $family_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $replaced_by
 * @property Carbon $created_at
 */
final class RefreshToken extends Model
{
    use HasUppercaseUlids;

    public const null UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'family_id', 'token_hash', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
