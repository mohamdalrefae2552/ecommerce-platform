<?php

declare(strict_types=1);

namespace App\Shared\Auth;

final readonly class IssuedToken
{
    public function __construct(
        public string $value,
        public int $expiresIn,
    ) {}
}
