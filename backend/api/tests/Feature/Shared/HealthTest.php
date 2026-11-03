<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

it('reports the API as up, with the inventory degraded until the gRPC client exists', function (): void {
    getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'checks' => [
                'db' => 'ok',
                'inventory' => 'degraded',
            ],
        ])
        ->assertMatchesContract();
});
