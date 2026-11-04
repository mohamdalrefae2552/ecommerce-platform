<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Users\Models\User;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Support\Facades\Hash;

/**
 * Checks credentials. Unknown email, wrong password and disabled account
 * all give the same INVALID_CREDENTIALS answer, and an unknown email still
 * pays for one argon2id verification so timing does not reveal accounts.
 */
final readonly class AuthenticateUser
{
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$Ck2ZDKSNYjUUXQ8IkCi6qQ4dhKNx0Nqk1U8tSqHpiTA';

    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);

            throw new ProblemException(ProblemCode::InvalidCredentials);
        }

        if (! Hash::check($password, $user->password) || $user->isDisabled()) {
            throw new ProblemException(ProblemCode::InvalidCredentials);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }
}
