<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

/**
 * Tells whether the inventory service answers in time. Implementations must
 * never block longer than their own deadline and never throw: a failure is
 * reported as degraded.
 */
interface InventoryHealthProbe
{
    public function check(): InventoryHealth;
}
