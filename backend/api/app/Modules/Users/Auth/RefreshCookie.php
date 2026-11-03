<?php

declare(strict_types=1);

namespace App\Modules\Users\Auth;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The refresh cookie (docs/04 section 2): `HttpOnly; Secure; SameSite=Strict;
 * Path=/api/v1/auth`, so it is only ever sent to the auth endpoints.
 */
final class RefreshCookie
{
    public const string NAME = 'refresh_token';

    public const string PATH = '/api/v1/auth';

    public static function make(string $token, int $ttlSeconds): Cookie
    {
        return Cookie::create(self::NAME, $token, Carbon::now()->addSeconds($ttlSeconds), self::PATH, null, true, true, false, Cookie::SAMESITE_STRICT);
    }

    public static function forget(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, self::PATH, null, true, true, false, Cookie::SAMESITE_STRICT);
    }
}
