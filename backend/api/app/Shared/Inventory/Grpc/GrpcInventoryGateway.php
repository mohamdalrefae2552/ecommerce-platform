<?php

declare(strict_types=1);

namespace App\Shared\Inventory\Grpc;

use App\Shared\Http\Middleware\RequestId;
use App\Shared\Inventory\Exceptions\InsufficientStock;
use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use App\Shared\Inventory\InventoryGateway;
use App\Shared\Inventory\ReleaseReason;
use App\Shared\Inventory\ReservationLine;
use App\Shared\Inventory\Shortage;
use App\Shared\Inventory\StockLevel;
use Closure;
use Ecommerce\Inventory\V1\AdjustStockRequest;
use Ecommerce\Inventory\V1\AdjustStockResponse;
use Ecommerce\Inventory\V1\GetStockRequest;
use Ecommerce\Inventory\V1\GetStockResponse;
use Ecommerce\Inventory\V1\InventoryServiceClient;
use Ecommerce\Inventory\V1\ReleaseOutcome;
use Ecommerce\Inventory\V1\ReleaseStockRequest;
use Ecommerce\Inventory\V1\ReleaseStockResponse;
use Ecommerce\Inventory\V1\ReservationItem;
use Ecommerce\Inventory\V1\ReserveOutcome;
use Ecommerce\Inventory\V1\ReserveStockRequest;
use Ecommerce\Inventory\V1\ReserveStockResponse;
use Ecommerce\Inventory\V1\StockLevel as GrpcStockLevel;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * InventoryGateway over the generated gRPC client (docs/03). Every call
 * carries an explicit deadline and the `x-request-id` metadata; status codes
 * become domain exceptions (section 3) and every answer is checked against
 * the request before it is trusted (section 4, OWASP API10).
 */
final readonly class GrpcInventoryGateway implements InventoryGateway
{
    private const int OK = 0;

    private const int CANCELLED = 1;

    private const int DEADLINE_EXCEEDED = 4;

    private const int RESOURCE_EXHAUSTED = 8;

    private const int FAILED_PRECONDITION = 9;

    private const int ABORTED = 10;

    private const int UNAVAILABLE = 14;

    public function __construct(
        private InventoryServiceClient $client,
        private InventoryConfig $config,
    ) {}

    public function stockFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $response = $this->call(
            fn (array $metadata, array $options): mixed => $this->client->GetStock((new GetStockRequest)->setProductIds($productIds), $metadata, $options),
            $this->config->getMs,
        );

        if (! $response instanceof GetStockResponse || count($response->getLevels()) !== count($productIds)) {
            throw new InventoryContractViolation('GetStock answered a different number of levels.');
        }

        $levels = [];
        $index = 0;
        foreach ($response->getLevels() as $level) {
            $id = $productIds[$index++];

            if (! $level instanceof GrpcStockLevel || $level->getProductId() !== $id || $level->getAvailable() < 0) {
                throw new InventoryContractViolation('GetStock answered an unexpected level.');
            }

            $levels[$id] = new StockLevel($id, (int) $level->getAvailable(), $level->getTracked());
        }

        return $levels;
    }

    public function reserve(string $reservationId, array $lines): void
    {
        $request = (new ReserveStockRequest)
            ->setReservationId($reservationId)
            ->setItems(array_map(
                static fn (ReservationLine $line): ReservationItem => (new ReservationItem)->setProductId($line->productId)->setQuantity($line->quantity),
                $lines,
            ));

        $send = fn (array $metadata, array $options): mixed => $this->client->ReserveStock($request, $metadata, $options);
        $started = hrtime(true);

        try {
            $response = $this->call($send, $this->config->reserveMs);
        } catch (InventoryUnavailable $e) {
            // One retry only (docs/03 section 2): safe because ReserveStock is
            // idempotent for the same reservation_id.
            $remaining = $this->config->orderBudgetMs - intdiv(hrtime(true) - $started, 1_000_000);

            if ($remaining < $this->config->retryMinRemainingMs) {
                throw $e;
            }

            $response = $this->call($send, min($this->config->reserveMs, $remaining));
        }

        if (! $response instanceof ReserveStockResponse || $response->getReservationId() !== $reservationId) {
            throw new InventoryContractViolation('ReserveStock answered another reservation.');
        }

        match ($response->getOutcome()) {
            ReserveOutcome::RESERVE_OUTCOME_RESERVED => null,
            ReserveOutcome::RESERVE_OUTCOME_INSUFFICIENT_STOCK => throw new InsufficientStock($this->shortages($response, $lines)),
            ReserveOutcome::RESERVE_OUTCOME_RESERVATION_ALREADY_RELEASED => throw new InventoryTimeout,
            default => throw new InventoryContractViolation('ReserveStock answered no outcome.'),
        };
    }

    public function release(string $reservationId, ReleaseReason $reason): void
    {
        $response = $this->call(
            fn (array $metadata, array $options): mixed => $this->client->ReleaseStock(
                (new ReleaseStockRequest)->setReservationId($reservationId)->setReason($reason->value),
                $metadata,
                $options,
            ),
            $this->config->releaseMs,
        );

        if (! $response instanceof ReleaseStockResponse
            || $response->getReservationId() !== $reservationId
            || $response->getOutcome() === ReleaseOutcome::RELEASE_OUTCOME_UNSPECIFIED) {
            throw new InventoryContractViolation('ReleaseStock answered another reservation or no outcome.');
        }
    }

    public function adjust(string $adjustmentId, string $productId, int $delta, string $note): int
    {
        try {
            $response = $this->call(
                fn (array $metadata, array $options): mixed => $this->client->AdjustStock(
                    (new AdjustStockRequest)->setAdjustmentId($adjustmentId)->setProductId($productId)->setDelta($delta)->setNote($note),
                    $metadata,
                    $options,
                ),
                $this->config->adjustMs,
            );
        } catch (StockWouldBeNegative) {
            // The status carries no quantity: read it to tell the admin.
            $available = $this->stockFor([$productId])[$productId]->available;

            throw new InsufficientStock([new Shortage($productId, abs($delta), $available)]);
        }

        if (! $response instanceof AdjustStockResponse
            || $response->getAdjustmentId() !== $adjustmentId
            || $response->getProductId() !== $productId
            || $response->getAvailable() < 0) {
            throw new InventoryContractViolation('AdjustStock answered another adjustment or a negative quantity.');
        }

        return (int) $response->getAvailable();
    }

    /**
     * Runs one unary call with its deadline and maps a non-OK status.
     *
     * @param  Closure(array<string, list<string>>, array{timeout: int}): mixed  $send
     */
    private function call(Closure $send, int $deadlineMs): mixed
    {
        $requestId = Context::get(RequestId::CONTEXT_KEY);
        $metadata = is_string($requestId) ? ['x-request-id' => [$requestId]] : [];

        $call = $send($metadata, ['timeout' => $deadlineMs * 1000]);

        if (! is_object($call) || ! method_exists($call, 'wait')) {
            throw new InventoryContractViolation('The gRPC client returned no call.');
        }

        /** @var array{0: mixed, 1: mixed} $result */
        $result = $call->wait();
        [$response, $status] = $result;
        $code = is_object($status) && isset($status->code) && is_int($status->code) ? $status->code : -1;

        if ($code === self::OK) {
            return $response;
        }

        $details = is_object($status) && isset($status->details) && is_string($status->details) ? $status->details : '';

        throw match ($code) {
            self::DEADLINE_EXCEEDED => new InventoryTimeout,
            self::UNAVAILABLE, self::ABORTED, self::RESOURCE_EXHAUSTED, self::CANCELLED => new InventoryUnavailable,
            self::FAILED_PRECONDITION => new StockWouldBeNegative($details),
            default => $this->violation($code, $details),
        };
    }

    private function violation(int $code, string $details): InventoryContractViolation
    {
        Log::error('Inventory call failed', ['grpc_code' => $code, 'details' => $details]);

        return new InventoryContractViolation('Inventory answered gRPC status '.$code.'.');
    }

    /**
     * @param  list<ReservationLine>  $lines
     * @return list<Shortage>
     */
    private function shortages(ReserveStockResponse $response, array $lines): array
    {
        $requested = [];
        foreach ($lines as $line) {
            $requested[$line->productId] = $line->quantity;
        }

        $shortages = [];
        foreach ($response->getShortages() as $shortage) {
            if (! isset($requested[$shortage->getProductId()]) || $shortage->getAvailable() < 0 || $shortage->getRequested() < 1) {
                throw new InventoryContractViolation('ReserveStock answered a shortage for a line that was not sent.');
            }

            $shortages[] = new Shortage($shortage->getProductId(), $shortage->getRequested(), (int) $shortage->getAvailable());
        }

        if ($shortages === []) {
            throw new InventoryContractViolation('ReserveStock answered INSUFFICIENT_STOCK without shortages.');
        }

        return $shortages;
    }
}
