<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\Migrations\MigrationRunner;
use PhpSoftBox\Database\Migrations\Snapshot\SqliteSchemaDumper;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PhpSoftBox\Database\Tests\Utils\Snapshot\SnapshotPlans;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(SqliteSchemaDumper::class)]
#[CoversMethod(MigrationRunner::class, 'migrate')]
#[CoversMethod(MigrationRunner::class, 'dump')]
final class MigrationSnapshotSqliteTest extends TestCase
{
    private const array ALL = [SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS, SnapshotPlans::NOTE];

    /**
     * Проверим, что пустая база из снимка плюс новая миграция совпадает с базой после всех миграций, включая строки справочника.
     *
     * @see MigrationRunner::migrate()
     * @see MigrationRunner::dump()
     */
    #[Test]
    public function freshDatabaseFromSnapshotMatchesFullMigrationRun(): void
    {
        // Снимок после двух первых миграций, затем их файлы удалены из каталога.
        $source = IntegrationDatabases::sqliteDatabase();
        $this->runner($source)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));
        $snapshot = $this->runner($source)->dump(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]), ['currencies']);

        $fresh   = IntegrationDatabases::sqliteDatabase();
        $applied = $this->runner($fresh)->migrate(SnapshotPlans::plan([SnapshotPlans::NOTE]), $snapshot);

        $full = IntegrationDatabases::sqliteDatabase();
        $this->runner($full)->migrate(SnapshotPlans::plan(self::ALL));

        self::assertSame([SnapshotPlans::NOTE], $applied);
        self::assertEquals(
            $this->runner($full)->dump(SnapshotPlans::plan(self::ALL), ['currencies']),
            $this->runner($fresh)->dump(SnapshotPlans::plan([SnapshotPlans::NOTE]), ['currencies']),
        );
    }

    /**
     * Проверим, что база, которая не получила свёрнутую в снимок и удалённую миграцию, не обновляется молча.
     *
     * @see MigrationRunner::migrate()
     */
    #[Test]
    public function rejectsDatabaseOlderThanSnapshot(): void
    {
        $source = IntegrationDatabases::sqliteDatabase();
        $this->runner($source)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));
        $snapshot = $this->runner($source)->dump(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));

        // Старая установка получила только первую миграцию.
        $old = IntegrationDatabases::sqliteDatabase();
        $this->runner($old)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(SnapshotPlans::ACCOUNTS);

        $this->runner($old)->migrate(SnapshotPlans::plan([SnapshotPlans::NOTE]), $snapshot);
    }

    /**
     * Проверим, что существующая база обновляется обычными миграциями, пока их файлы есть, и снимок не загружается повторно.
     *
     * @see MigrationRunner::migrate()
     */
    #[Test]
    public function upgradesExistingDatabaseWithRegularMigrations(): void
    {
        $source = IntegrationDatabases::sqliteDatabase();
        $this->runner($source)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));
        $snapshot = $this->runner($source)->dump(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));

        $old = IntegrationDatabases::sqliteDatabase();
        $this->runner($old)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES]));

        self::assertSame(
            [SnapshotPlans::ACCOUNTS, SnapshotPlans::NOTE],
            $this->runner($old)->migrate(SnapshotPlans::plan(self::ALL), $snapshot),
        );
    }

    /**
     * Проверим, что снимок не снимается с базы, в которой есть непримененные миграции.
     *
     * @see MigrationRunner::dump()
     */
    #[Test]
    public function dumpRequiresAppliedMigrations(): void
    {
        $db = IntegrationDatabases::sqliteDatabase();
        $this->runner($db)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES]));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(SnapshotPlans::ACCOUNTS);

        $this->runner($db)->dump(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));
    }

    private function runner(Database $db): MigrationRunner
    {
        return new MigrationRunner($db->manager(), connectionName: 'main');
    }
}
