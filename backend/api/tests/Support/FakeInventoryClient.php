<?php

declare(strict_types=1);

namespace Tests\Support;

use Ecommerce\Inventory\V1\AdjustStockRequest;
use Ecommerce\Inventory\V1\GetStockRequest;
use Ecommerce\Inventory\V1\InventoryServiceClient;
use Ecommerce\Inventory\V1\ReleaseStockRequest;
use Ecommerce\Inventory\V1\ReserveStockRequest;
use Google\Protobuf\Internal\Message;
use Grpc\ChannelCredentials;

/**
 * Generated client with scripted answers instead of a network: each call
 * pops the next [response, status code] and records its metadata and
 * options (to prove that every call carries a deadline).
 */
final class FakeInventoryClient extends InventoryServiceClient
{
    /** @var list<array{method: string, request: Message, metadata: mixed, options: mixed}> */
    public array $calls = [];

    /** @var list<array{0: Message|null, 1: int, 2: string}> */
    private array $answers = [];

    public function __construct()
    {
        parent::__construct('localhost:1', ['credentials' => ChannelCredentials::createInsecure()]);
    }

    public function answer(?Message $response, int $code = 0, string $details = ''): self
    {
        $this->answers[] = [$response, $code, $details];

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $metadata
     * @param  array<string, mixed>  $options
     */
    public function GetStock(GetStockRequest $argument, $metadata = [], $options = []): object
    {
        // @phpstan-ignore return.type (a scripted call stands in for Grpc\UnaryCall)
        return $this->next('GetStock', $argument, $metadata, $options);
    }

    /**
     * @param  array<string, list<string>>  $metadata
     * @param  array<string, mixed>  $options
     */
    public function ReserveStock(ReserveStockRequest $argument, $metadata = [], $options = []): object
    {
        // @phpstan-ignore return.type (a scripted call stands in for Grpc\UnaryCall)
        return $this->next('ReserveStock', $argument, $metadata, $options);
    }

    /**
     * @param  array<string, list<string>>  $metadata
     * @param  array<string, mixed>  $options
     */
    public function ReleaseStock(ReleaseStockRequest $argument, $metadata = [], $options = []): object
    {
        // @phpstan-ignore return.type (a scripted call stands in for Grpc\UnaryCall)
        return $this->next('ReleaseStock', $argument, $metadata, $options);
    }

    /**
     * @param  array<string, list<string>>  $metadata
     * @param  array<string, mixed>  $options
     */
    public function AdjustStock(AdjustStockRequest $argument, $metadata = [], $options = []): object
    {
        // @phpstan-ignore return.type (a scripted call stands in for Grpc\UnaryCall)
        return $this->next('AdjustStock', $argument, $metadata, $options);
    }

    private function next(string $method, Message $request, mixed $metadata, mixed $options): object
    {
        $this->calls[] = ['method' => $method, 'request' => $request, 'metadata' => $metadata, 'options' => $options];
        [$response, $code, $details] = array_shift($this->answers) ?? [null, 2, 'no scripted answer'];

        return new class($response, $code, $details)
        {
            public function __construct(private ?Message $response, private int $code, private string $details) {}

            /**
             * @return array{0: Message|null, 1: object}
             */
            public function wait(): array
            {
                return [$this->response, (object) ['code' => $this->code, 'details' => $this->details, 'metadata' => []]];
            }
        };
    }
}
