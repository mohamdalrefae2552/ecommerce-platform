<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Modules\Products\Database\Factories\CategoryFactory;
use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 */
#[UseFactory(CategoryFactory::class)]
final class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasUppercaseUlids;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['slug', 'name'];
}
