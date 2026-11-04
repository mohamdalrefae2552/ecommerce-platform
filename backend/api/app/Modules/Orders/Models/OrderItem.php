<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An order line with a snapshot of the product name and price at order time.
 *
 * @property int $id
 * @property string $order_id
 * @property string $product_id
 * @property string $product_name
 * @property int $unit_price_minor
 * @property int $quantity
 */
final class OrderItem extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = ['product_id', 'product_name', 'unit_price_minor', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_price_minor' => 'integer', 'quantity' => 'integer'];
    }
}
