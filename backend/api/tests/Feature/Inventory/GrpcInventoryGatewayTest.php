<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\RequestId;
use App\Shared\Inventory\Exceptions\InsufficientStock;
use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use App\Shared\Inventory\Grpc\GrpcInventoryGateway;
use App\Shared\Inventory\Grpc\InventoryConfig;
use App\Shared\Inventory\ReleaseReason;
use App\Shared\Inventory\ReservationLine;
use Ecommerce\Inventory\V1\AdjustStockResponse;
use Ecommerce\Inventory\V1\GetStockResponse;
use Ecommerce\Inventory\V1\ReleaseOutcome;
use Ecommerce\Inventory\V1\ReleaseStockResponse;
use Ecommerce\Inventory\V1\ReserveOutcome;
use Ecommerce\Inventory\V1\ReserveStockResponse;
use Ecommerce\Inventory\V1\Shortage as GrpcShortage;
use Ecommerce\Inventory\V1\StockLevel as GrpcStockLevel;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Context;
use Tests\Support\FakeInventoryClient;

/*
| GrpcInventoryGateway against a scripted client (docs/03 sections 2-4).
*/

const PRODUCT_A = '01JC40A1B2C3D4E5F6G7H8J9KM';
const PRODUCT_B = '01JC40A1B2C3D4E5F6G7H8J9KN';
const RESERVATION = '7d1f2c3e-4b5a-4c6d-8e7f-901a2b3c4d5e';

function gateway(FakeInventoryClient $client, int $budgetMs = 3000): GrpcInventoryGateway
{
    return new GrpcInventoryGateway($client, new InventoryConfig('localhost:1', 500, 2000, 1000, 2000, $budgetMs, 1000));
}

function reserved(string $reservationId = RESERVATION): ReserveStockResponse
{
    return (new ReserveStockResponse)->setReservationId($reservationId)->setOutcome(ReserveOutcome::RESERVE_OUTCOME_RESERVED);
}

it('sends every call with its own deadline and the request id', function (): void {
    Context::add(RequestId::CONTEXT_KEY, '01JC3Z9A1B2C3D4E5F6G7H8J9K');
    $client = (new FakeInventoryClient)
        ->answer((new GetStockResponse)->setLevels([(new GrpcStockLevel)->setProductId(PRODUCT_A)->setAvailable(3)->setTracked(true)]))
        ->answer(reserved())
        ->answer((new ReleaseStockResponse)->setReservationId(RESERVATION)->setOutcome(ReleaseOutcome::RELEASE_OUTCOME_RELEASED))
        ->answer((new AdjustStockResponse)->setAdjustmentId('adj')->setProductId(PRODUCT_A)->setAvailable(8));
    $gateway = gateway($client);

    expect($gateway->stockFor([PRODUCT_A])[PRODUCT_A]->available)->toBe(3);
    $gateway->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)]);
    $gateway->release(RESERVATION, ReleaseReason::OrderCancelled);
    expect($gateway->adjust('adj', PRODUCT_A, 5, ''))->toBe(8);

    expect(array_map(fn (array $call): mixed => $call['options'], $client->calls))
        ->toBe([['timeout' => 500_000], ['timeout' => 2_000_000], ['timeout' => 1_000_000], ['timeout' => 2_000_000]])
        ->and($client->calls[0]['metadata'])->toBe(['x-request-id' => ['01JC3Z9A1B2C3D4E5F6G7H8J9K']]);
});

it('maps gRPC status codes to domain exceptions', function (int $code, string $exception): void {
    $client = (new FakeInventoryClient)->answer(null, $code);

    expect(fn () => gateway($client)->stockFor([PRODUCT_A]))->toThrow($exception);
})->with([
    'DEADLINE_EXCEEDED' => [4, InventoryTimeout::class],
    'UNAVAILABLE' => [14, InventoryUnavailable::class],
    'ABORTED' => [10, InventoryUnavailable::class],
    'RESOURCE_EXHAUSTED' => [8, InventoryUnavailable::class],
    'CANCELLED' => [1, InventoryUnavailable::class],
    'INVALID_ARGUMENT' => [3, InventoryContractViolation::class],
    'INTERNAL' => [13, InventoryContractViolation::class],
]);

it('turns an insufficient outcome (OK status) into InsufficientStock with the shortages', function (): void {
    $client = (new FakeInventoryClient)->answer((new ReserveStockResponse)
        ->setReservationId(RESERVATION)
        ->setOutcome(ReserveOutcome::RESERVE_OUTCOME_INSUFFICIENT_STOCK)
        ->setShortages([(new GrpcShortage)->setProductId(PRODUCT_B)->setRequested(2)->setAvailable(1)]));

    $caught = null;
    try {
        gateway($client)->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1), new ReservationLine(PRODUCT_B, 2)]);
    } catch (InsufficientStock $e) {
        $caught = $e;
    }

    expect($caught?->problemExtensions())->toBe(['shortages' => [['product_id' => PRODUCT_B, 'requested' => 2, 'available' => 1]]]);
});

it('treats an already released reservation as a timeout', function (): void {
    $client = (new FakeInventoryClient)->answer((new ReserveStockResponse)->setReservationId(RESERVATION)->setOutcome(ReserveOutcome::RESERVE_OUTCOME_RESERVATION_ALREADY_RELEASED));

    expect(fn () => gateway($client)->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)]))->toThrow(InventoryTimeout::class);
});

it('retries ReserveStock once on UNAVAILABLE, with the same reservation id', function (): void {
    $client = (new FakeInventoryClient)->answer(null, 14)->answer(reserved());

    gateway($client)->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)]);

    expect($client->calls)->toHaveCount(2);
});

it('does not retry when less than 1000 ms of the order budget remain', function (): void {
    $client = (new FakeInventoryClient)->answer(null, 14)->answer(reserved());

    expect(fn () => gateway($client, budgetMs: 900)->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)]))->toThrow(InventoryUnavailable::class)
        ->and($client->calls)->toHaveCount(1);
});

it('never retries a timeout', function (): void {
    $client = (new FakeInventoryClient)->answer(null, 4)->answer(reserved());

    expect(fn () => gateway($client)->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)]))->toThrow(InventoryTimeout::class)
        ->and($client->calls)->toHaveCount(1);
});

it('refuses answers that do not match the request', function (Closure $answer, Closure $call): void {
    $response = $answer();
    assert($response instanceof Message);
    $client = (new FakeInventoryClient)->answer($response);

    expect(fn () => $call(gateway($client)))->toThrow(InventoryContractViolation::class);
})->with([
    'another reservation id' => [fn () => reserved('00000000-0000-4000-8000-000000000000'), fn (GrpcInventoryGateway $g) => $g->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)])],
    'no outcome' => [fn () => (new ReserveStockResponse)->setReservationId(RESERVATION), fn (GrpcInventoryGateway $g) => $g->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)])],
    'missing level' => [fn () => new GetStockResponse, fn (GrpcInventoryGateway $g) => $g->stockFor([PRODUCT_A])],
    'level of another product' => [fn () => (new GetStockResponse)->setLevels([(new GrpcStockLevel)->setProductId(PRODUCT_B)]), fn (GrpcInventoryGateway $g) => $g->stockFor([PRODUCT_A])],
    'negative quantity' => [fn () => (new GetStockResponse)->setLevels([(new GrpcStockLevel)->setProductId(PRODUCT_A)->setAvailable(-1)]), fn (GrpcInventoryGateway $g) => $g->stockFor([PRODUCT_A])],
    'insufficient without shortages' => [fn () => (new ReserveStockResponse)->setReservationId(RESERVATION)->setOutcome(ReserveOutcome::RESERVE_OUTCOME_INSUFFICIENT_STOCK), fn (GrpcInventoryGateway $g) => $g->reserve(RESERVATION, [new ReservationLine(PRODUCT_A, 1)])],
]);

it('reports a write-off larger than the stock as InsufficientStock with the current quantity', function (): void {
    $client = (new FakeInventoryClient)
        ->answer(null, 9, 'the adjustment would make the available quantity negative')
        ->answer((new GetStockResponse)->setLevels([(new GrpcStockLevel)->setProductId(PRODUCT_A)->setAvailable(2)->setTracked(true)]));

    $caught = null;
    try {
        gateway($client)->adjust('adj', PRODUCT_A, -5, '');
    } catch (InsufficientStock $e) {
        $caught = $e;
    }

    expect($caught?->problemExtensions())->toBe(['shortages' => [['product_id' => PRODUCT_A, 'requested' => 5, 'available' => 2]]]);
});
