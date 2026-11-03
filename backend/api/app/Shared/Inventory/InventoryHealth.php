<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

/**
 * Inventory part of GET /api/v1/health (contract schema Health.checks.inventory).
 */
enum InventoryHealth: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
}
