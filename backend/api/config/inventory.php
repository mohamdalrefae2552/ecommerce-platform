<?php

declare(strict_types=1);

// gRPC client of the inventory service (docs/03 section 2). Every call has
// its own deadline; ReserveStock may be retried once on UNAVAILABLE while at
// least 1000 ms of the order budget remain.
return [
    // Through toxiproxy, so latency and outages can be injected (make chaos-*).
    'target' => env('INVENTORY_GRPC_TARGET', 'toxiproxy:50051'),

    'deadlines_ms' => [
        'get' => (int) env('INVENTORY_DEADLINE_GET_MS', 500),
        'reserve' => (int) env('INVENTORY_DEADLINE_RESERVE_MS', 2000),
        'release' => (int) env('INVENTORY_DEADLINE_RELEASE_MS', 1000),
        'adjust' => (int) env('INVENTORY_DEADLINE_ADJUST_MS', 2000),
    ],

    'order_budget_ms' => (int) env('INVENTORY_ORDER_BUDGET_MS', 3000),
    'retry_min_remaining_ms' => 1000,
];
