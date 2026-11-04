<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;

/**
 * The inventory is `ok` when a GetStock for one probe id is answered within
 * the GetStock deadline (500 ms); anything else is `degraded`.
 */
final readonly class GatewayInventoryHealthProbe implements InventoryHealthProbe
{
    // A valid ULID that is never a product: the answer is "not tracked".
    private const string PROBE_PRODUCT_ID = '00000000000000000000000000';

    public function __construct(private InventoryGateway $inventory) {}

    public function check(): InventoryHealth
    {
        try {
            $this->inventory->stockFor([self::PROBE_PRODUCT_ID]);

            return InventoryHealth::Ok;
        } catch (InventoryUnavailable|InventoryTimeout|InventoryContractViolation) {
            return InventoryHealth::Degraded;
        }
    }
}
