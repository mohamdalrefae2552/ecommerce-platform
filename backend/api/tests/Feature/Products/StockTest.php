<?php

declare(strict_types=1);

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/*
| Stock with products (R2-F2), graceful degradation (PROOF-R2-2) and stock
| management through AdjustStock (D-01).
*/

it('reads the stock of a whole page with one inventory call', function (): void {
    Product::factory()->count(19)->create();
    inventory()->setStock(Product::factory()->create()->id, 5);

    getJson('/api/v1/products?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertMatchesContract();

    expect(inventory()->stockCalls)->toBe(1);
});

it('keeps product pages up when the inventory is down or slow', function (Throwable $failure): void {
    $product = Product::factory()->create();

    inventory()->failNext('stock', $failure);
    getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonPath('meta.degraded', ['inventory'])
        ->assertJsonPath('data.0.stock', ['status' => 'unknown', 'available' => null])
        ->assertMatchesContract();

    inventory()->failNext('stock', $failure);
    getJson('/api/v1/products/'.$product->id)
        ->assertOk()
        ->assertJsonPath('data.stock', ['status' => 'unknown', 'available' => null])
        ->assertMatchesContract();
})->with(['timeout' => [new InventoryTimeout], 'unavailable' => [new InventoryUnavailable]]);

it('still creates the product when the initial stock cannot be registered', function (): void {
    Storage::fake('public');
    inventory()->failNext('adjust', new InventoryUnavailable);

    $response = call('POST', '/api/v1/products', [
        'name' => 'Phone', 'price_minor' => '1000', 'category_id' => Category::factory()->create()->id, 'initial_stock' => '3',
    ], [], ['image' => UploadedFile::fake()->image('p.png', 10, 10)], [
        'HTTP_AUTHORIZATION' => 'Bearer '.jwtFor(User::factory()->admin()->create()),
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'multipart/form-data',
    ]);

    $response->assertCreated()->assertJsonPath('data.stock.status', 'unknown')->assertMatchesContract();
});

it('lets the admin restock and write off through AdjustStock', function (): void {
    $product = Product::factory()->create();
    inventory()->setStock($product->id, 2);
    $admin = User::factory()->admin()->create();

    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => 5, 'note' => 'delivery'], bearer($admin) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()
        ->assertExactJson(['data' => ['product_id' => $product->id, 'available' => 7]])
        ->assertMatchesContract();

    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => -8], bearer($admin) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertConflict()
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortages', [['product_id' => $product->id, 'requested' => 8, 'available' => 7]])
        ->assertMatchesContract();
});

it('derives one adjustment id per Idempotency-Key and product', function (): void {
    $product = Product::factory()->create();
    $admin = User::factory()->admin()->create();
    $key = (string) Str::uuid();

    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => 1], bearer($admin) + ['Idempotency-Key' => $key])->assertOk();
    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => 1], bearer($admin) + ['Idempotency-Key' => $key])->assertOk();
    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => 1], bearer($admin) + ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    $ids = array_column(inventory()->adjustments, 0);
    expect($ids[0])->toBe($ids[1])->and($ids[2] === $ids[0])->toBeFalse()->and(Str::isUuid($ids[0]))->toBeTrue();
});

it('answers 503 and changes nothing when the inventory is down', function (Throwable $failure, string $code): void {
    $product = Product::factory()->create();
    inventory()->failNext('adjust', $failure);

    putJson('/api/v1/products/'.$product->id.'/stock', ['delta' => 1], bearer(User::factory()->admin()->create()) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(503)
        ->assertJsonPath('code', $code)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('retry_after', 5)
        ->assertMatchesContract();
})->with([
    'unavailable' => [new InventoryUnavailable, 'INVENTORY_UNAVAILABLE'],
    'timeout' => [new InventoryTimeout, 'INVENTORY_TIMEOUT'],
]);

it('validates stock adjustments', function (array $headers, array $body, int $status): void {
    $product = Product::factory()->create();

    putJson('/api/v1/products/'.$product->id.'/stock', $body, bearer(User::factory()->admin()->create()) + $headers)
        ->assertStatus($status)
        ->assertMatchesContract();
})->with([
    'missing key' => [[], ['delta' => 1], 400],
    'malformed key' => [['Idempotency-Key' => 'nope'], ['delta' => 1], 400],
    'zero delta' => [['Idempotency-Key' => '7d1f2c3e-4b5a-4c6d-8e7f-901a2b3c4d5e'], ['delta' => 0], 422],
    'too large' => [['Idempotency-Key' => '7d1f2c3e-4b5a-4c6d-8e7f-901a2b3c4d5e'], ['delta' => 1000001], 422],
]);

it('answers an unknown product with 404', function (): void {
    putJson('/api/v1/products/01JC40A1B2C3D4E5F6G7H8J9KM/stock', ['delta' => 1], bearer(User::factory()->admin()->create()) + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertNotFound()
        ->assertMatchesContract();
});
