<?php

declare(strict_types=1);

namespace App\Modules\Products\Cache;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Observer of Product and Category: any write invalidates the catalogue
 * cache. It runs AFTER the transaction commits: invalidating earlier would
 * let a concurrent reader refill the new version with the old row (read
 * before the commit), which would then be served until the TTL.
 */
final readonly class InvalidateCatalog implements ShouldHandleEventsAfterCommit
{
    public function __construct(private CatalogCache $cache) {}

    public function saved(): void
    {
        $this->cache->invalidate();
    }

    public function deleted(): void
    {
        $this->cache->invalidate();
    }
}
