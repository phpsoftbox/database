<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
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
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(new FakePdo('mysql')));

        self::assertInstanceOf(MariaDbSchemaCompiler::class, $this->getCompiler($builder));
    }

    /**
     * Проверяет, что SchemaBuilderFactory выбирает правильный компилятор для Postgres.
     */
    #[Test]
    public function createsPostgresBuilder(): void
    {
        $builder = new SchemaBuilderFactory()->create(new SpyConnection(new FakePdo('pgsql')));

        self::assertInstanceOf(PostgresSchemaCompiler::class, $this->getCompiler($builder));
    }

    /**
     * Проверяет, что SchemaBuilderFactory кидает исключение для неподдерживаемого драйвера.
     */
    #[Test]
    public function throwsForUnknownDriver(): void
    {
        $this->expectException(ConfigurationException::class);

        new SchemaBuilderFactory()->create(new SpyConnection(new FakePdo('oracle')));
    }

    private function getCompiler(SchemaBuilder $builder): object
    {
        $rp = new ReflectionProperty(SchemaBuilder::class, 'compiler');

        $rp->setAccessible(true);

        return $rp->getValue($builder);
    }
}
