<?php

declare(strict_types=1);

namespace App\Modules\Users\Contracts;

/**
 * Public read interface of the Users module (docs/01 section 3).
 */
interface UserDirectory
{
    public function contact(string $userId): ?UserContact;
}
