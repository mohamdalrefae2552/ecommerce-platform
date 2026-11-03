<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;

/**
 * PSR-20 clock over Carbon, so token times follow `travel()` in tests.
 */
final readonly class CarbonClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
