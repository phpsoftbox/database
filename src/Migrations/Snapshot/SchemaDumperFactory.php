<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

final class SchemaDumperFactory
{
    public static function create(ConnectionInterface $connection): SchemaDumperInterface
    {
        return match ($connection->driver()->name()) {
            'mysql', 'mariadb' => new MySqlSchemaDumper(),
            'sqlite'           => new SqliteSchemaDumper(),
            default            => throw new ConfigurationException('Снимок схемы не поддерживается для драйвера ' . $connection->driver()->name() . '.'),
        };
    }
}
