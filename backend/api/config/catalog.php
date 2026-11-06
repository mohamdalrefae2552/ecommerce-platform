<?php

declare(strict_types=1);

/*
| Catalogue cache (requirement 3, docs/performance-report.md). Correctness
| comes from invalidation on every committed product or category write; the
| TTL only bounds how long an entry nobody reads stays in Redis.
*/

return [
    'cache_ttl' => (int) env('CATALOG_CACHE_TTL', 600),
];
