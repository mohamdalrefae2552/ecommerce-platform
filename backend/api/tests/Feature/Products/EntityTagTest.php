<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\seed;

/*
| HTTP ETag (requirement 3, R3-6): the public catalogue reads answer 304
| without a body while nothing shown changed; per-user endpoints never get
| an ETag and stay `no-store`.
*/

beforeEach(function (): void {
    seed(CategorySeeder::class);
});

dataset('catalogue', [
    'categories' => fn (): string => '/api/v1/categories',
    'product list' => fn (): string => '/api/v1/products',
    'product list by category' => fn (): string => '/api/v1/products?category='.Product::factory()->create()->category->slug,
    'product details' => fn (): string => '/api/v1/products/'.Product::factory()->create()->id,
]);

it('sends a strong ETag and answers 304 with no body when it still matches', function (string $uri): void {
    $first = getJson($uri)->assertOk()->assertMatchesContract();
    $etag = (string) $first->headers->get('ETag');

    expect($etag)->toMatch('/^"[0-9a-f]{64}"$/')
        ->and($etag)->toBe('"'.hash('sha256', (string) $first->getContent()).'"');
    $first->assertHeader('Cache-Control', 'no-cache, private');

    $second = getJson($uri, ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertHeader('ETag', $etag)
        ->assertHeader('X-Request-Id')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertMatchesContract();
    expect($second->getContent())->toBe('');

    getJson($uri, ['If-None-Match' => '"stale"'])->assertOk();
})->with('catalogue');

it('keeps no-cache and the ETag for a logged-in caller (same public data)', function (): void {
    $product = Product::factory()->create();
    $customer = User::factory()->create();

    $etag = (string) getJson('/api/v1/products/'.$product->id, bearer($customer))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, private')
        ->headers->get('ETag');

    getJson('/api/v1/products/'.$product->id, ['If-None-Match' => $etag, ...bearer($customer)])->assertStatus(304);
});

it('changes the ETag when the product is edited', function (): void {
    $product = Product::factory()->create(['name' => 'Before']);
    $etag = (string) getJson('/api/v1/products/'.$product->id)->headers->get('ETag');

    patchJson('/api/v1/products/'.$product->id, ['name' => 'After'], bearer(User::factory()->admin()->create()))->assertOk();

    getJson('/api/v1/products/'.$product->id, ['If-None-Match' => $etag])
        ->assertOk()
        ->assertJsonPath('data.name', 'After');
});

it('changes the ETag when only the stock changes', function (): void {
    $product = Product::factory()->create();
    inventory()->available[$product->id] = 3;
    $etag = (string) getJson('/api/v1/products/'.$product->id)->headers->get('ETag');

    inventory()->available[$product->id] = 2;

    getJson('/api/v1/products/'.$product->id, ['If-None-Match' => $etag])
        ->assertOk()
        ->assertJsonPath('data.stock.available', 2);
});

it('never tags errors', function (): void {
    getJson('/api/v1/products/01JC0000000000000000000000')->assertNotFound()->assertHeaderMissing('ETag');
    getJson('/api/v1/products?per_page=1000')->assertUnprocessable()->assertHeaderMissing('ETag');
});

it('never tags per-user endpoints, which stay no-store', function (string $uri): void {
    $customer = User::factory()->create();
    $order = Order::factory()->ownedBy($customer)->create();

    getJson(str_replace('{order}', $order->id, $uri), bearer($customer))
        ->assertOk()
        ->assertHeaderMissing('ETag')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with(['/api/v1/orders', '/api/v1/orders/{order}', '/api/v1/notifications', '/api/v1/auth/me']);
