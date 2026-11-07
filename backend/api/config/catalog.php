<?php

declare(strict_types=1);

/*
| Catalogue cache (requirement 3, docs/performance-report.md). Correctness
| comes from invalidation on every committed product or category write; the
| TTL only bounds how long an entry nobody reads stays in Redis.
*/

return [
    // false = behaviour before requirement 3 (the live before/after demo,
    // `make perf-compare`): every read goes to the database, nothing is
    // read from or written to the cache.
    'cache_enabled' => (bool) filter_var(env('CATALOG_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
    'cache_ttl' => (int) env('CATALOG_CACHE_TTL', 600),
];
