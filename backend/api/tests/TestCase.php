<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

abstract class TestCase extends BaseTestCase
{
    use ValidatesOpenApiSchema;

    /**
     * Used by `$response->assertMatchesContract()` (Tests\Support\ContractAssertions).
     *
     * @param  TestResponse<covariant \Symfony\Component\HttpFoundation\Response>  $response
     */
    final public function assertResponseMatchesContract(TestResponse $response): void
    {
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
