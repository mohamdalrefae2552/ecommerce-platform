<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

use App\Shared\Inventory\Exceptions\InsufficientStock;
use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;

/**
 * The only way the application talks to the inventory service (gRPC,
 * contracts/proto/inventory/v1). Laravel stores no quantities: every read
 * and write of stock goes through here. Every call has a deadline
 * (config/inventory.php) and failures become domain exceptions (docs/03
 * section 3).
 *
 * @throws InventoryUnavailable|InventoryTimeout|InventoryContractViolation from every method
 */
interface InventoryGateway
{
    /**
     * One batched call for up to 100 products.
     *
     * @param  list<string>  $productIds  upper-case ULIDs, no duplicates
     * @return array<string, StockLevel> keyed by product id
     */
    public function stockFor(array $productIds): array;

    /**
     * Reserves every line or nothing (R2-F1).
     *
     * @param  list<ReservationLine>  $lines
     *
     * @throws InsufficientStock when a line cannot be served (no error status: a business outcome)
     */
    public function reserve(string $reservationId, array $lines): void;

    /**
     * Returns a reservation's units (idempotent; an unknown id is tombstoned).
     */
    public function release(string $reservationId, ReleaseReason $reason): void;

    /**
     * Restock (delta > 0) or write-off (delta < 0); returns the new available quantity.
     *
     * @throws InsufficientStock when a write-off is larger than the available quantity
     */
    public function adjust(string $adjustmentId, string $productId, int $delta, string $note): int;
}
