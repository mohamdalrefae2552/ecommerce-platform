<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Grpc;

use Illuminate\Contracts\Config\Repository;

/**
 * Typed view of config/inventory.php (deadlines in milliseconds).
 */
final readonly class InventoryConfig
{
    public function __construct(
        public string $target,
        public int $getMs,
        public int $reserveMs,
        public int $releaseMs,
        public int $adjustMs,
        public int $orderBudgetMs,
        public int $retryMinRemainingMs,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $int = static function (string $key) use ($config): int {
            $value = $config->get('inventory.'.$key);

            return is_int($value) && $value > 0 ? $value : 1;
        };
        $target = $config->get('inventory.target');

        return new self(
            is_string($target) ? $target : '',
            $int('deadlines_ms.get'),
            $int('deadlines_ms.reserve'),
            $int('deadlines_ms.release'),
            $int('deadlines_ms.adjust'),
            $int('order_budget_ms'),
            $int('retry_min_remaining_ms'),
        );
    }
}
