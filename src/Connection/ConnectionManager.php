<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Connection;

use PhpSoftBox\Database\Configurator\DatabaseFactoryInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;

/**
 * Ленивая обёртка над DatabaseFactory: создаёт подключения по требованию и кэширует их.
 */
final class ConnectionManager implements ConnectionManagerInterface
{
    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    public function __construct(
        private readonly DatabaseFactoryInterface $factory,
    ) {
    }

    public function connection(string $name = 'default'): ConnectionInterface
    {
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->factory->create($name);
        }

        return $this->connections[$name];
    }

    public function read(string $name = 'default'): ConnectionInterface
    {
        return $this->connection($name . '.read');
    }

    public function write(string $name = 'default'): ConnectionInterface
    {
        return $this->connection($name . '.write');
    }
}
