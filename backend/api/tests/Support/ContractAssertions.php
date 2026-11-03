<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use LogicException;
use Pest\TestSuite;
use Tests\TestCase;

/**
 * `$response->assertMatchesContract()`: validates status, headers and body of
 * a response against contracts/openapi/openapi.yaml (ADR-0002). A thin
 * wrapper over the contract-testing library (ADR-0010) so the library can be
 * replaced without touching the tests.
 *
 * Registered by tests/Pest.php, and by tests/Support/phpstan-bootstrap.php so
 * Larastan knows the macro.
 */
final class ContractAssertions
{
    public static function registerMacro(): void
    {
        TestResponse::macro('assertMatchesContract', function (): TestResponse {
            $test = TestSuite::getInstance()->test;

            if (! $test instanceof TestCase) {
                throw new LogicException('assertMatchesContract() is only available in tests extending Tests\TestCase.');
            }

            $test->assertResponseMatchesContract($this);

            return $this;
        });
    }
}
