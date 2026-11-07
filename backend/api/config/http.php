<?php

declare(strict_types=1);

/*
| HTTP behaviour toggles (requirement 3).
*/

return [
    // ETag + 304 on the public catalogue reads (App\Shared\Http\Middleware\EntityTag).
    // false = behaviour before requirement 3 (`make perf-compare` baseline).
    'etag_enabled' => (bool) filter_var(env('HTTP_ETAG_ENABLED', true), FILTER_VALIDATE_BOOL),
];
