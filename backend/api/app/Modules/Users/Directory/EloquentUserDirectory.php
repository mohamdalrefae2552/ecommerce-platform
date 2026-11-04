<?php

declare(strict_types=1);

namespace App\Modules\Users\Directory;

use App\Modules\Users\Contracts\UserContact;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Models\User;

final readonly class EloquentUserDirectory implements UserDirectory
{
    public function contact(string $userId): ?UserContact
    {
        $user = User::query()->find($userId, ['id', 'name', 'email']);

        return $user === null ? null : new UserContact($user->id, $user->name, $user->email);
    }
}
