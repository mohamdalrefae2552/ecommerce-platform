<?php

declare(strict_types=1);

namespace App\Shared\Messaging\Amqp;

use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * Opens RabbitMQ connections from config/messaging.php. Every connection has
 * explicit connect and I/O deadlines.
 */
final readonly class AmqpConnector
{
    private const int WORKER_HEARTBEAT_SECONDS = 30;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost,
        private float $connectTimeout,
        private float $ioTimeout,
    ) {}

    /**
     * Short-lived connection of a publisher (an HTTP request or the relay).
     */
    public function forPublishing(): AMQPStreamConnection
    {
        return $this->connect($this->ioTimeout, 0);
    }

    /**
     * Long-lived connection of a worker: heartbeats detect a dead broker.
     */
    public function forConsuming(): AMQPStreamConnection
    {
        return $this->connect(self::WORKER_HEARTBEAT_SECONDS * 2.0, self::WORKER_HEARTBEAT_SECONDS);
    }

    private function connect(float $ioTimeout, int $heartbeat): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->password,
            $this->vhost,
            connection_timeout: $this->connectTimeout,
            read_write_timeout: $ioTimeout,
            heartbeat: $heartbeat,
        );
    }
}
