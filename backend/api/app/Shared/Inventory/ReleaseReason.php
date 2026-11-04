<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

/**
 * Why units are given back (proto ReleaseReason).
 */
enum ReleaseReason: int
{
    case OrderPersistenceFailed = 1;
    case ReserveOutcomeUnknown = 2;
    case OrderCancelled = 3;
    case Reconciliation = 4;
}
