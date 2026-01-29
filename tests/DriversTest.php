<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Dsn\Dsn;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteDriver::class)]
#[CoversClass(MariaDbDriver::class)]
#[CoversClass(PostgresDriver::class)]
final class DriversTest extends TestCase
{
    /**
     * Проверяет, что SqliteDriver собирает корректный PDO DSN для :memory:.
     */
    #[Test]
    public function sqliteBuildsPdoDsnForMemory(): void
    {
        $pdoDsn = new SqliteDriver()->pdoDsn(new Dsn(driver: 'sqlite', path: ':memory:'));

        self::assertSame('sqlite::memory:', $pdoDsn);
    }

    /**
     * Проверяет, что PostgresDriver строит PDO DSN и использует порт по умолчанию.
     */
    #[Test]
    public function postgresBuildsPdoDsn(): void
    {
        $pdoDsn = new PostgresDriver()->pdoDsn(new Dsn(driver: 'postgres', host: 'localhost', database: 'app'));

        self::assertSame('pgsql:host=localhost;port=5432;dbname=app', $pdoDsn);
    }

    /**
     * Проверяет, что MariaDbDriver строит PDO DSN и добавляет charset из params, если он задан.
     */
    #[Test]
    public function mariadbBuildsPdoDsnWithCharset(): void
    {
        $pdoDsn = new MariaDbDriver()->pdoDsn(new Dsn(driver: 'mariadb', host: 'localhost', port: 3307, database: 'app', params: ['charset' => 'utf8mb4']));

        self::assertSame('mysql:host=localhost;port=3307;dbname=app;charset=utf8mb4', $pdoDsn);
    }

    /**
     * Проверяет, что драйверы валидируют DSN и кидают ConfigurationException при отсутствии обязательных частей.
     */
    #[Test]
    public function driversValidateDsn(): void
    {
        $this->expectException(ConfigurationException::class);
        new PostgresDriver()->pdoDsn(new Dsn(driver: 'postgres', host: null, database: null));
    }
}
