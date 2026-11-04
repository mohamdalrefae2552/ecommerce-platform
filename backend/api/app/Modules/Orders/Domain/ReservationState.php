<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

/**
 * State of a saga attempt (docs/05 section 3).
 */
enum ReservationState: string
{
    case Pending = 'PENDING';
    case Confirmed = 'CONFIRMED';
    case Rejected = 'REJECTED';
    case Released = 'RELEASED';
    case ReleasePending = 'RELEASE_PENDING';
}
