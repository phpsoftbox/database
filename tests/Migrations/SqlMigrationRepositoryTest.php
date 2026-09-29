<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PhpSoftBox\Database\Driver\MySqlDriver;
use PhpSoftBox\Database\Migrations\SqlMigrationRepository;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function count;
use function str_starts_with;

#[CoversClass(SqlMigrationRepository::class)]
#[CoversMethod(SqlMigrationRepository::class, 'ensureTable')]
final class SqlMigrationRepositoryTest extends TestCase
{
    /**
     * Проверим, что таблица миграций создаётся один раз на подключение, даже если ensureTable() вызывается
     * из appliedIds()/markApplied()/removeApplied() повторно.
     *
     * @see SqlMigrationRepository::ensureTable()
     */
    #[Test]
    public function ensuresTableOncePerConnection(): void
    {
        $connection = new SpyConnection(new FakePdo('mysql'), driver: new MySqlDriver());
        $repository = new SqlMigrationRepository('migrations');

        $repository->ensureTable($connection);
        $repository->appliedIds($connection, 'main');
        $repository->markApplied($connection, '20260101000000_first', 'main');
        $repository->removeApplied($connection, '20260101000000_first', 'main');

        $creates = array_filter(
            $connection->executed,
            static fn (array $query): bool => str_starts_with($query['sql'], 'CREATE TABLE'),
        );

        self::assertSame(1, count($creates));
    }
}
