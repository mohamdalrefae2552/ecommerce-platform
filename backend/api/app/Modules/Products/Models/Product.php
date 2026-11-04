<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Modules\Products\Database\Factories\ProductFactory;
use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A catalogue entry. Money is integer minor units (SYP, 2 decimals). The
 * stock is never stored here (it belongs to the inventory service).
 *
 * @property string $id
 * @property string $name
 * @property string $description
 * @property int $price_minor
 * @property string $currency
 * @property string $category_id
 * @property string $image ULID file name of the WebP image
 * @property Category $category
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(ProductFactory::class)]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasUppercaseUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'description', 'price_minor', 'category_id', 'image'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['description' => '', 'currency' => 'SYP'];

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price_minor' => 'integer'];
    }
}
