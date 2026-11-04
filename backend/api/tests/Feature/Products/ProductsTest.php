<?php

declare(strict_types=1);

use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\travel;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * @param  array<string, mixed>  $fields
 * @param  array<string, string>  $headers
 * @return TestResponse<Response>
 */
function createProduct(array $fields, array $headers = []): TestResponse
{
    $files = array_filter($fields, fn (mixed $value): bool => $value instanceof UploadedFile);
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return call('POST', '/api/v1/products', array_diff_key($fields, $files), [], $files, $server);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productFields(Category $category, array $overrides = []): array
{
    return [
        'initial_stock' => '3',
        'name' => 'هاتف ذكي X200',
        'description' => 'شاشة 6.5 إنش.',
        'price_minor' => '450000000',
        'category_id' => $category->id,
        'image' => UploadedFile::fake()->image('phone.png', 640, 480),
        ...$overrides,
    ];
}

it('lists the seeded categories', function (): void {
    seed(CategorySeeder::class);

    getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertMatchesContract();
});

it('lets an admin add a product with its image re-encoded to WebP', function (): void {
    $category = Category::factory()->create();

    $response = createProduct(productFields($category), bearer(User::factory()->admin()->create()))
        ->assertCreated()
        ->assertJsonPath('data.price.amount_minor', 450000000)
        ->assertJsonPath('data.price.currency', 'SYP')
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.stock', ['status' => 'in_stock', 'available' => 3])
        ->assertMatchesContract();

    $product = Product::query()->sole();
    expect(inventory()->adjustments)->toHaveCount(1)->and(inventory()->adjustments[0][2])->toBe(3);
    $response->assertHeader('Location', url('/api/v1/products/'.$product->id));
    expect($product->image)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}\.webp$/')
        ->and($response->json('data.image_url'))->toBe('http://localhost:8080/storage/products/'.$product->image);

    $stored = Storage::disk('public')->get('products/'.$product->image);
    expect(substr((string) $stored, 0, 4))->toBe('RIFF')->and(substr((string) $stored, 8, 4))->toBe('WEBP');
});

it('refuses to add a product for a customer (403) and a guest (401)', function (): void {
    $category = Category::factory()->create();

    createProduct(productFields($category), bearer(User::factory()->create()))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertMatchesContract();

    createProduct(productFields($category))
        ->assertUnauthorized()
        ->assertMatchesContract();

    expect(Product::query()->count())->toBe(0);
});

it('validates the product and its image', function (Closure $overrides, string $field): void {
    $category = Category::factory()->create();

    $fields = $overrides();
    assert(is_array($fields));

    /** @var array<string, mixed> $fields */
    createProduct(productFields($category, $fields), bearer(User::factory()->admin()->create()))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]])
        ->assertMatchesContract();

    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'not an image' => [fn (): array => ['image' => UploadedFile::fake()->create('fake.png', 10, 'text/plain')], 'image'],
    'image above 2 MB' => [fn (): array => ['image' => UploadedFile::fake()->image('big.png', 100, 100)->size(2049)], 'image'],
    'image too wide' => [fn (): array => ['image' => UploadedFile::fake()->image('wide.png', 4001, 10)], 'image'],
    'missing image' => [fn (): array => ['image' => null], 'image'],
    'zero price' => [fn (): array => ['price_minor' => '0'], 'price_minor'],
    'unknown category' => [fn (): array => ['category_id' => '01JC3Z0Y1E2C3T4R5N6C7S8A9B'], 'category_id'],
    'unknown field' => [fn (): array => ['stock' => '5'], 'stock'],
]);

it('rejects an image with a valid header but undecodable bytes without storing anything', function (): void {
    $category = Category::factory()->create();
    $canvas = imagecreatetruecolor(10, 10);
    ob_start();
    imagepng($canvas);
    $png = (string) ob_get_clean();
    // Keep the signature, IHDR and the IDAT chunk header, then replace the pixel data with garbage.
    $corrupt = substr($png, 0, (int) strpos($png, 'IDAT') + 4).str_repeat("\x00garbage", 40);

    createProduct(
        productFields($category, ['image' => UploadedFile::fake()->createWithContent('corrupt.png', $corrupt)]),
        bearer(User::factory()->admin()->create()),
    )
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['image']])
        ->assertMatchesContract();

    expect(Product::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('lists products newest first with the contract pagination envelope', function (): void {
    $category = Category::factory()->create();
    $products = [];
    foreach (range(1, 3) as $ignored) {
        travel(1)->minutes();
        $products[] = Product::factory()->for($category)->create(['created_at' => Carbon::now()]);
    }

    $response = getJson('/api/v1/products?per_page=2')
        ->assertOk()
        ->assertJsonPath('data.0.id', $products[2]->id)
        ->assertJsonPath('data.1.id', $products[1]->id)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonMissingPath('meta.degraded')
        ->assertJsonPath('links.prev', null)
        ->assertJsonPath('data.0.stock', ['status' => 'out_of_stock', 'available' => 0])
        ->assertMatchesContract();

    $next = $response->json('links.next');
    assert(is_string($next));

    getJson($next)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $products[0]->id);
});

it('filters by category slug and name', function (): void {
    $books = Category::factory()->create(['slug' => 'books']);
    $match = Product::factory()->for($books)->create(['name' => 'Dune Messiah']);
    Product::factory()->for($books)->create(['name' => 'Foundation']);
    Product::factory()->create(['name' => 'Dune poster']);

    getJson('/api/v1/products?category=books&q=dune')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id)
        ->assertMatchesContract();
});

it('rejects invalid list parameters with 422 instead of clamping', function (string $query, string $field): void {
    getJson('/api/v1/products?'.$query)
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]])
        ->assertMatchesContract();
})->with([
    ['per_page=101', 'per_page'],
    ['per_page=0', 'per_page'],
    ['page=0', 'page'],
    ['page=100001', 'page'],
    ['category=unknown-slug', 'category'],
    ['q=', 'q'],
]);

it('shows one product with its stock', function (): void {
    $product = Product::factory()->create();
    inventory()->setStock($product->id, 4);

    getJson('/api/v1/products/'.$product->id)
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.stock', ['status' => 'in_stock', 'available' => 4])
        ->assertMatchesContract();
});

it('answers an unknown product with 404', function (): void {
    getJson('/api/v1/products/01JC40A1B2C3D4E5F6G7H8J9KM')
        ->assertNotFound()
        ->assertJsonPath('code', 'NOT_FOUND')
        ->assertMatchesContract();
});
