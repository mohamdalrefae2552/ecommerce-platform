<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApiContract;

/*
| Contract first (ADR-0002): the application exposes no endpoint that is not in
| contracts/openapi/openapi.yaml. Contract operations that are not implemented
| yet are allowed; each feature task adds its routes together with tests.
*/

/**
 * @return list<string>
 */
function registeredOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            // Laravel adds HEAD to every GET route automatically.
            if ($method !== 'HEAD') {
                $operations[] = OpenApiContract::operation($method, $route->uri());
            }
        }
    }

    sort($operations);

    return array_values(array_unique($operations));
}

it('registers only operations documented in the OpenAPI contract', function (): void {
    expect(array_values(array_diff(registeredOperations(), OpenApiContract::operations())))->toBe([]);
});

it('serves every endpoint under the versioned base path of the contract', function (): void {
    $base = OpenApiContract::basePath();

    foreach (registeredOperations() as $operation) {
        expect(explode(' ', $operation, 2)[1])->toStartWith($base.'/');
    }
});

it('implements the health operation', function (): void {
    expect(registeredOperations())->toContain('GET /api/v1/health');
});
