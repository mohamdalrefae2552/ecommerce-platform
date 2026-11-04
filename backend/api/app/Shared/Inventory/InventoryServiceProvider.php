<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

use App\Shared\Inventory\Grpc\GrpcInventoryGateway;
use App\Shared\Inventory\Grpc\InventoryConfig;
use Ecommerce\Inventory\V1\InventoryServiceClient;
use Grpc\ChannelCredentials;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Container bindings of the inventory client side (the only way Laravel talks
 * to the inventory service; there is no stock data in this application).
 * The gRPC channel is created lazily, once per PHP-FPM worker.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        InventoryGateway::class => GrpcInventoryGateway::class,
        InventoryHealthProbe::class => GatewayInventoryHealthProbe::class,
    ];

    public function register(): void
    {
        $this->app->singleton(InventoryConfig::class, fn (Application $app): InventoryConfig => InventoryConfig::fromRepository($app->make(Repository::class)));

        // Plaintext inside the isolated Docker network (ADR-0004; mTLS with
        // requirement 5).
        $this->app->singleton(InventoryServiceClient::class, fn (Application $app): InventoryServiceClient => new InventoryServiceClient(
            $app->make(InventoryConfig::class)->target,
            ['credentials' => ChannelCredentials::createInsecure()],
        ));
    }
}
