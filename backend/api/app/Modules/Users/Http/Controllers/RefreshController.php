<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Auth\RefreshCookie;
use App\Modules\Users\Auth\RefreshTokenService;
use App\Modules\Users\Auth\TokenResponder;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * POST /api/v1/auth/refresh (refreshToken). CSRF guard first (docs/04
 * section 2): `X-Requested-With: fetch` and an Origin equal to APP_URL,
 * otherwise 403 and nothing is rotated.
 */
final readonly class RefreshController
{
    public function __construct(
        private RefreshTokenService $refreshTokens,
        private TokenResponder $responder,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->header('X-Requested-With') !== 'fetch' || ! self::sameOrigin($request->header('Origin'))) {
            throw new ProblemException(ProblemCode::Forbidden);
        }

        $plain = $request->cookies->get(RefreshCookie::NAME);

        if (! is_string($plain) || $plain === '') {
            throw new ProblemException(ProblemCode::RefreshTokenInvalid);
        }

        [$user, $next] = $this->refreshTokens->rotate($plain);

        return $this->responder->respond($request, $user, $next);
    }

    private static function sameOrigin(mixed $origin): bool
    {
        $app = parse_url(Config::string('app.url'));

        if (! is_string($origin) || ! is_array($app) || ! isset($app['scheme'], $app['host'])) {
            return false;
        }

        $expected = $app['scheme'].'://'.$app['host'].(isset($app['port']) ? ':'.$app['port'] : '');

        return strcasecmp(rtrim($origin, '/'), $expected) === 0;
    }
}
