<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Exceptions;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;

/**
 * gRPC DEADLINE_EXCEEDED (or a reservation already released after an
 * earlier timeout) ⇒ 503 INVENTORY_TIMEOUT with Retry-After: 5.
 */
final class InventoryTimeout extends ProblemException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct(ProblemCode::InventoryTimeout, $detail, ['retry_after' => 5], ['Retry-After' => '5']);
    }
}
