<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Inventory\Exceptions\InsufficientStock;
use App\Shared\Inventory\InventoryGateway;
use App\Shared\Inventory\ReleaseReason;
use App\Shared\Inventory\ReservationLine;
use App\Shared\Inventory\Shortage;
use App\Shared\Inventory\StockLevel;
use Throwable;

/**
 * In-memory inventory for feature tests (bound by Tests\TestCase): keeps
 * quantities, records every call and can be told to fail one operation.
 */
final class FakeInventoryGateway implements InventoryGateway
{
    /** @var array<string, int> */
    public array $available = [];

    /** @var array<string, list<ReservationLine>> */
    public array $reservations = [];

    /** @var list<array{0: string, 1: ReleaseReason}> */
    public array $releases = [];

    /** @var list<array{0: string, 1: string, 2: int}> */
    public array $adjustments = [];

    public int $stockCalls = 0;

    /** Units of every product not set explicitly (null: untracked, 0 units). */
    public ?int $defaultAvailable = null;

    /** @var array<string, Throwable> */
    private array $failures = [];

    public function failNext(string $operation, Throwable $error): self
    {
        $this->failures[$operation] = $error;

        return $this;
    }

    public function setStock(string $productId, int $available): self
    {
        $this->available[$productId] = $available;

        return $this;
    }

    public function stockFor(array $productIds): array
    {
        $this->stockCalls++;
        $this->maybeFail('stock');

        $levels = [];
        foreach ($productIds as $id) {
            $levels[$id] = new StockLevel($id, $this->units($id), isset($this->available[$id]) || $this->defaultAvailable !== null);
        }

        return $levels;
    }

    public function reserve(string $reservationId, array $lines): void
    {
        $this->maybeFail('reserve');

        $shortages = [];
        foreach ($lines as $line) {
            $available = $this->units($line->productId);
            if ($available < $line->quantity) {
                $shortages[] = new Shortage($line->productId, $line->quantity, $available);
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStock($shortages);
        }

        foreach ($lines as $line) {
            $this->available[$line->productId] = $this->units($line->productId) - $line->quantity;
        }

        $this->reservations[$reservationId] = $lines;
    }

    public function release(string $reservationId, ReleaseReason $reason): void
    {
        $this->releases[] = [$reservationId, $reason];
        $this->maybeFail('release');

        foreach ($this->reservations[$reservationId] ?? [] as $line) {
            $this->available[$line->productId] = $this->units($line->productId) + $line->quantity;
        }

        unset($this->reservations[$reservationId]);
    }

    public function adjust(string $adjustmentId, string $productId, int $delta, string $note): int
    {
        $this->adjustments[] = [$adjustmentId, $productId, $delta];
        $this->maybeFail('adjust');

        $available = $this->units($productId);
        if ($available + $delta < 0) {
            throw new InsufficientStock([new Shortage($productId, abs($delta), $available)]);
        }

        return $this->available[$productId] = $available + $delta;
    }

    private function units(string $productId): int
    {
        return $this->available[$productId] ?? $this->defaultAvailable ?? 0;
    }

    private function maybeFail(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            $error = $this->failures[$operation];
            unset($this->failures[$operation]);

            throw $error;
        }
    }
}
