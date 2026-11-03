<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

/**
 * Probe used while no inventory gRPC client exists: it makes no network call
 * and reports degraded, because the inventory cannot be confirmed reachable.
 *
 * Replaced by a gRPC-backed probe in task P2-API-01 (InventoryGateway).
 */
final readonly class UnconfiguredInventoryHealthProbe implements InventoryHealthProbe
{
    public function check(): InventoryHealth
    {
        return InventoryHealth::Degraded;
    }
}
