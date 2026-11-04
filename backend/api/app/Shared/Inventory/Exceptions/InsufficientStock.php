<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Exceptions;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use App\Shared\Inventory\Shortage;

/**
 * 409 INSUFFICIENT_STOCK with every short line, ordered by product id.
 */
final class InsufficientStock extends ProblemException
{
    /**
     * @param  list<Shortage>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        $sorted = $shortages;
        usort($sorted, static fn (Shortage $a, Shortage $b): int => strcmp($a->productId, $b->productId));

        parent::__construct(ProblemCode::InsufficientStock, null, [
            'shortages' => array_map(static fn (Shortage $s): array => $s->toArray(), $sorted),
        ]);
    }
}
