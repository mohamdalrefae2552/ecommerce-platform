<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Exceptions;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;

/**
 * gRPC UNAVAILABLE, ABORTED, RESOURCE_EXHAUSTED or CANCELLED ⇒ 503
 * INVENTORY_UNAVAILABLE with Retry-After: 5.
 */
final class InventoryUnavailable extends ProblemException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct(ProblemCode::InventoryUnavailable, $detail, ['retry_after' => 5], ['Retry-After' => '5']);
    }
}
