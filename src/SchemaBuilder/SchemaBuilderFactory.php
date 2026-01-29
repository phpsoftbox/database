<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PDO;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SchemaCompilerInterface;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;

use function sprintf;
use function strtolower;

final class SchemaBuilderFactory
{
    public function create(ConnectionInterface $connection): SchemaBuilderInterface
    {
        $driver = strtolower((string) $connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME));

        $compiler = $this->createCompiler($driver);

        return new SchemaBuilder($connection, $compiler);
    }

    private function createCompiler(string $driver): SchemaCompilerInterface
    {
        return match ($driver) {
            'sqlite' => new SqliteSchemaCompiler(),
            'mysql'  => new MariaDbSchemaCompiler(),
            'pgsql'  => new PostgresSchemaCompiler(),
            default  => throw new ConfigurationException(sprintf('SchemaBuilder is not implemented for driver "%s".', $driver)),
        };
    }
}
