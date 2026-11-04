<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Grpc;

use RuntimeException;

/**
 * FAILED_PRECONDITION from AdjustStock: the write-off is larger than the
 * available quantity. Internal to the gateway, which turns it into
 * InsufficientStock with the current quantity.
 */
final class StockWouldBeNegative extends RuntimeException {}
