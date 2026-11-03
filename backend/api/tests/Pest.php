<?php

declare(strict_types=1);

use App\Shared\Auth\AuthUser;
use App\Shared\Auth\TokenIssuer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\ContractAssertions;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

ContractAssertions::registerMacro();

/**
 * A real access token for the user, signed with the test key.
 */
function jwtFor(AuthUser $user): string
{
    return app(TokenIssuer::class)->issue($user)->value;
}

/**
 * @return array{Authorization: string}
 */
function bearer(AuthUser $user): array
{
    return ['Authorization' => 'Bearer '.jwtFor($user)];
}
