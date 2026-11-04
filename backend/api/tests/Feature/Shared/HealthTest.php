<?php

declare(strict_types=1);

use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;

use function Pest\Laravel\getJson;

it('reports the API and the inventory as up', function (): void {
    getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['db' => 'ok', 'inventory' => 'ok']])
        ->assertMatchesContract();
});

it('reports the inventory as degraded when it does not answer in time, and stays 200', function (Throwable $failure): void {
    inventory()->failNext('stock', $failure);

    getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('checks.inventory', 'degraded')
        ->assertMatchesContract();
})->with([
    'timeout' => [new InventoryTimeout],
    'unavailable' => [new InventoryUnavailable],
]);
