<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

use Illuminate\Support\ServiceProvider;

/**
 * Container bindings of the inventory client side (the only way Laravel talks
 * to the inventory service; there is no stock data in this application).
 */
final class InventoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        InventoryHealthProbe::class => UnconfiguredInventoryHealthProbe::class,
    ];
}
