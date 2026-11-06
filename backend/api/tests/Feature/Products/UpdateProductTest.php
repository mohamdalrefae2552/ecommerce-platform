<?php

declare(strict_types=1);

use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\seed;

beforeEach(function (): void {
    seed(CategorySeeder::class);
});

it('lets an admin edit name, description, price and category', function (): void {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Old name', 'price_minor' => 1000]);
    $books = Category::query()->where('slug', 'books')->sole();
    inventory()->available[$product->id] = 4;

    patchJson('/api/v1/products/'.strtolower($product->id), [
        'name' => '  New name  ',
        'description' => 'Fresh text',
        'price_minor' => 2500,
        'category_id' => strtolower($books->id),
    ], bearer($admin))
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.name', 'New name')
        ->assertJsonPath('data.description', 'Fresh text')
        ->assertJsonPath('data.price.amount_minor', 2500)
        ->assertJsonPath('data.category.slug', 'books')
        ->assertJsonPath('data.stock.available', 4)
        ->assertMatchesContract();

    expect($product->refresh()->name)->toBe('New name')
        ->and($product->category_id)->toBe($books->id);
});

it('changes only the fields that are sent', function (): void {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Keep me', 'description' => 'Keep too', 'price_minor' => 1000]);

    patchJson('/api/v1/products/'.$product->id, ['price_minor' => 1500], bearer($admin))
        ->assertOk()
        ->assertJsonPath('data.name', 'Keep me')
        ->assertJsonPath('data.description', 'Keep too')
        ->assertJsonPath('data.price.amount_minor', 1500)
        ->assertMatchesContract();
});

it('refuses an edit from a customer (403) and a guest (401) before reading the body', function (): void {
    $product = Product::factory()->create(['name' => 'Untouched']);

    patchJson('/api/v1/products/'.$product->id, ['name' => 'Hacked'], bearer(User::factory()->create()))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertMatchesContract();
    patchJson('/api/v1/products/'.$product->id, ['name' => 'Hacked'])
        ->assertUnauthorized()
        ->assertMatchesContract();

    expect($product->refresh()->name)->toBe('Untouched');
});

it('answers an unknown product with 404', function (): void {
    patchJson('/api/v1/products/01JC0000000000000000000000', ['name' => 'Nobody'], bearer(User::factory()->admin()->create()))
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertMatchesContract();
});

it('validates the edit', function (array $body, string $field): void {
    $product = Product::factory()->create();

    patchJson('/api/v1/products/'.$product->id, $body, bearer(User::factory()->admin()->create()))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => [$field]])
        ->assertMatchesContract();
})->with([
    'empty body' => [[], 'name'],
    'short name' => [['name' => 'x'], 'name'],
    'zero price' => [['price_minor' => 0], 'price_minor'],
    'unknown category' => [['category_id' => '01JC0000000000000000000000'], 'category_id'],
    'image is not editable here' => [['image' => 'x.webp'], 'image'],
    'stock is not editable here' => [['stock' => 5], 'stock'],
]);

it('shows the edit on the very next read of the details and the list', function (): void {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Before edit', 'price_minor' => 1000]);

    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.name', 'Before edit');
    getJson('/api/v1/products')->assertJsonPath('data.0.name', 'Before edit');

    patchJson('/api/v1/products/'.$product->id, ['name' => 'After edit', 'price_minor' => 2000], bearer($admin))->assertOk();

    getJson('/api/v1/products/'.$product->id)
        ->assertJsonPath('data.name', 'After edit')
        ->assertJsonPath('data.price.amount_minor', 2000);
    getJson('/api/v1/products')->assertJsonPath('data.0.name', 'After edit');
});
