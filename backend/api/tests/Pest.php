<?php

declare(strict_types=1);

use App\Shared\Auth\AuthUser;
use App\Shared\Auth\TokenIssuer;
use App\Shared\Inventory\InventoryGateway;
use App\Shared\Messaging\MessagePublisher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\ContractAssertions;
use Tests\Support\FakeInventoryGateway;
use Tests\Support\FakeMessagePublisher;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

ContractAssertions::registerMacro();

/**
 * The in-memory inventory bound by Tests\TestCase.
 */
function inventory(): FakeInventoryGateway
{
    $gateway = app(InventoryGateway::class);

    if (! $gateway instanceof FakeInventoryGateway) {
        throw new LogicException('The fake inventory is not bound.');
    }

    return $gateway;
}

/**
 * The in-memory message broker bound by Tests\TestCase.
 */
function broker(): FakeMessagePublisher
{
    $publisher = app(MessagePublisher::class);

    if (! $publisher instanceof FakeMessagePublisher) {
        throw new LogicException('The fake message broker is not bound.');
    }

    return $publisher;
}

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
