<?php

declare(strict_types=1);

namespace App\Shared\Http\Controllers;

use App\Shared\Inventory\InventoryHealthProbe;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/health (operation getHealth). No sensitive information: only
 * the fixed status values of the contract schema Health. When the database
 * does not answer the query throws and the request fails with 500.
 */
final readonly class HealthController
{
    public function __construct(
        private ConnectionInterface $database,
        private InventoryHealthProbe $inventory,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->database->select('select 1');

        return new JsonResponse([
            'status' => 'ok',
            'checks' => [
                'db' => 'ok',
                'inventory' => $this->inventory->check()->value,
            ],
        ]);
    }
}
