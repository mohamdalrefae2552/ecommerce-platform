<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * ULID primary keys in their canonical upper-case Crockford form, as the
 * contract's Ulid schema requires (Laravel's HasUlids lower-cases them).
 */
trait HasUppercaseUlids
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }
}
