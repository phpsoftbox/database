<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function sprintf;
use function strtolower;

/**
 * Фабрика SchemaManager по типу драйвера.
 *
 * На первых итерациях маппинг простой и явный.
 */
final class SchemaManagerFactory
{
    public function create(ConnectionInterface $connection, string $driver): SchemaManagerInterface
    {
        $driver = strtolower($driver);

        return match ($driver) {
            'sqlite' => new SqliteSchemaManager($connection),
            'mysql', 'mariadb' => new MariaDbSchemaManager($connection),
            'pgsql', 'postgres' => new PostgresSchemaManager($connection),
            default => throw new ConfigurationException(sprintf('Schema introspection is not implemented for driver "%s".', $driver)),
        };
    }
}
