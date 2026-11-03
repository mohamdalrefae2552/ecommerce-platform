<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Auth\JwtConfig;
use App\Shared\Auth\JwtKeyStore;
use App\Shared\Auth\TokenIssuer;
use App\Shared\Auth\TokenVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

abstract class TestCase extends BaseTestCase
{
    use ValidatesOpenApiSchema;

    private static ?string $jwtKeysPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // One throw-away Ed25519 key pair per test process.
        if (self::$jwtKeysPath === null) {
            self::$jwtKeysPath = sys_get_temp_dir().'/jwt-test-keys-'.getmypid();
            $store = new JwtKeyStore(self::$jwtKeysPath);

            if (! $store->exists()) {
                $store->generate();
            }
        }

        config(['jwt.keys_path' => self::$jwtKeysPath]);

        foreach ([JwtConfig::class, JwtKeyStore::class, TokenIssuer::class, TokenVerifier::class] as $service) {
            $this->app->forgetInstance($service);
        }
    }

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
