<?php

declare(strict_types=1);

namespace App\Modules\Orders\Database\Factories;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Shared\Auth\AuthUser;
use App\Shared\Auth\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Orders for tests: one line and the initial `pending` history entry, like
 * an order placed through the API.
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => (string) Str::ulid(),
            'status' => OrderStatus::Pending,
            'total_minor' => 2 * 150000,
        ];
    }

    public function ownedBy(AuthUser $user): self
    {
        return $this->state(['user_id' => $user->userId()]);
    }

    public function status(OrderStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function configure(): self
    {
        return $this->afterCreating(function (Order $order): void {
            $order->items()->create([
                'product_id' => (string) Str::ulid(),
                'product_name' => 'Test product',
                'unit_price_minor' => 150000,
                'quantity' => 2,
            ]);
            $order->history()->create([
                'from_status' => null,
                'to_status' => OrderStatus::Pending,
                'actor_id' => $order->user_id,
                'actor_role' => Role::Customer,
                'at' => Carbon::now(),
            ]);
        });
    }
}
