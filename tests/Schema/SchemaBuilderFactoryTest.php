<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Driver\AbstractMySqlDriver;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\MySqlDriver;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(SchemaBuilderFactory::class)]
final class SchemaBuilderFactoryTest extends TestCase
{
    /**
     * Проверяет, что SchemaBuilderFactory выбирает правильный компилятор для SQLite.
     */
    #[Test]
    public function createsSqliteBuilder(): void
    {
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(new FakePdo('sqlite')));

        self::assertInstanceOf(SchemaBuilder::class, $builder);
        self::assertInstanceOf(SqliteSchemaCompiler::class, $this->getCompiler($builder));
    }

    /**
     * Проверяет, что SchemaBuilderFactory выбирает правильный компилятор для MySQL/MariaDB.
     */
    #[Test]
    public function createsMariaDbBuilder(): void
    {
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(
            new FakePdo('mysql'),
            driver: new MariaDbDriver(),
        ));

        self::assertInstanceOf(MariaDbSchemaCompiler::class, $this->getCompiler($builder));
    }

    #[Test]
    public function createsMySqlBuilder(): void
    {
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(
            new FakePdo('mysql'),
            driver: new MySqlDriver(),
        ));

        self::assertInstanceOf(MySqlSchemaCompiler::class, $this->getCompiler($builder));
    }

    /**
     * Проверяет, что SchemaBuilderFactory выбирает правильный компилятор для Postgres.
     */
    #[Test]
    public function createsPostgresBuilder(): void
    {
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(
            new FakePdo('pgsql'),
            driver: new PostgresDriver(),
        ));

        self::assertInstanceOf(PostgresSchemaCompiler::class, $this->getCompiler($builder));
    }

    /**
     * Проверяет, что SchemaBuilderFactory кидает исключение для неподдерживаемого драйвера.
     */
    #[Test]
    public function throwsForUnknownDriver(): void
    {
        $this->expectException(ConfigurationException::class);

        $driver = new class () extends AbstractMySqlDriver {
            public function name(): string
            {
                return 'unknown';
            }

            protected function displayName(): string
            {
                return 'Unknown';
            }
        };

        new SchemaBuilderFactory()->create(new SpyConnection(new FakePdo('mysql'), driver: $driver));
    }

    private function getCompiler(SchemaBuilder $builder): object
    {
        $rp = new ReflectionProperty(SchemaBuilder::class, 'compiler');

        return $rp->getValue($builder);
    }
}
