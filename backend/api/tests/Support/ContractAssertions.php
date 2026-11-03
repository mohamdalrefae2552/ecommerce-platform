<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use LogicException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Pest\TestSuite;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * Contract assertions on TestResponse (ADR-0002), thin wrappers over the
 * contract-testing library (ADR-0010) so it can be replaced without touching
 * the tests:
 *
 * - `assertMatchesContract()`: status, headers and body against the documented
 *   operation in contracts/openapi/openapi.yaml.
 * - `assertMatchesContractSchema('Problem')`: body against one component schema,
 *   for responses with no documented operation (unknown route, 405).
 *
 * Registered by tests/Pest.php, and by tests/Support/phpstan-bootstrap.php so
 * Larastan knows the macros.
 */
final class ContractAssertions
{
    private const string DOCUMENT_ID = 'https://contract.ecommerce.local/openapi.json';

    private static ?Validator $validator = null;

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

        TestResponse::macro('assertMatchesContractSchema', function (string $schema): TestResponse {
            ContractAssertions::assertMatchesSchema((string) $this->getContent(), $schema);

            return $this;
        });
    }

    public static function assertMatchesSchema(string $json, string $schema): void
    {
        $result = self::validator()->validate(
            json_decode($json, false, 512, JSON_THROW_ON_ERROR),
            self::DOCUMENT_ID.'#/components/schemas/'.$schema,
        );

        $error = $result->error();
        $errors = $error === null ? [] : (new ErrorFormatter)->format($error, false);

        Assert::assertTrue($result->isValid(), 'Body does not match schema '.$schema.': '.json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            $document = json_decode(
                json_encode(OpenApiContract::document(), JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );

            if (! is_object($document)) {
                throw new LogicException('The contract is not a JSON object.');
            }

            $validator = new Validator;
            $validator->resolver()?->registerRaw($document, self::DOCUMENT_ID);
            self::$validator = $validator;
        }

        return self::$validator;
    }
}
