<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PDO;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Schema\SchemaManagerFactory;
use PhpSoftBox\Database\Schema\SchemaManagerInterface;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;

/**
 * Контекст выполнения миграции.
 *
 * Идея: миграции не должны напрямую принимать/менять подключение.
 * Контекст выдаёт read-only доступ к соединению и удобные сервисы поверх него.
 */
final readonly class MigrationContext
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    public function connection(): ConnectionInterface
    {
        return $this->connection;
    }

    /**
     * Schema builder (DDL/изменение схемы).
     */
    public function schema(): SchemaBuilderInterface
    {
        return new SchemaBuilderFactory()->create($this->connection);
    }

    /**
     * Introspection (чтение схемы).
     */
    public function schemaManager(): SchemaManagerInterface
    {
        $driver = (string) $this->connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return new SchemaManagerFactory()->create($this->connection, $driver);
    }
}
