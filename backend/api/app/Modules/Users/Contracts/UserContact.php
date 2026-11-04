<?php

declare(strict_types=1);

namespace App\Modules\Users\Contracts;

/**
 * What other modules may know about a user to contact them.
 */
final readonly class UserContact
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}
}
