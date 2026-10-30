<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PDO;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Driver\DriversEnum;
use PhpSoftBox\Database\Migrations\MigrationRunner;
use PhpSoftBox\Database\Migrations\Snapshot\MySqlSchemaDumper;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PhpSoftBox\Database\Tests\Utils\Snapshot\SnapshotPlans;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(MySqlSchemaDumper::class)]
#[CoversMethod(MigrationRunner::class, 'migrate')]
#[CoversMethod(MigrationRunner::class, 'dump')]
final class MigrationSnapshotMariaDbTest extends TestCase
{
    private const array ALL = [SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS, SnapshotPlans::NOTE];

    private const array DATABASES = ['psb_snapshot_source', 'psb_snapshot_fresh', 'psb_snapshot_full'];

    private Database $root;

    protected function setUp(): void
    {
        try {
            $this->root = IntegrationDatabases::mariadbDatabase($this->config('phpsoftbox'));
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        foreach (self::DATABASES as $name) {
            $this->root->execute('DROP DATABASE IF EXISTS ' . $name);
            $this->root->execute('CREATE DATABASE ' . $name);
        }
    }

    protected function tearDown(): void
    {
        foreach (isset($this->root) ? self::DATABASES : [] as $name) {
            $this->root->execute('DROP DATABASE IF EXISTS ' . $name);
        }
    }

    /**
     * Проверим, что на MariaDB пустая база из снимка плюс новая миграция совпадает с базой после всех миграций:
     * внешние ключи, комментарии, JSON-проверки, строки справочника и следующий AUTO_INCREMENT.
     *
     * @see MigrationRunner::migrate()
     * @see MigrationRunner::dump()
     */
    #[Test]
    public function freshDatabaseFromSnapshotMatchesFullMigrationRun(): void
    {
        $source = $this->database('psb_snapshot_source');
        $this->runner($source)->migrate(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]));
        $snapshot = $this->runner($source)->dump(SnapshotPlans::plan([SnapshotPlans::CURRENCIES, SnapshotPlans::ACCOUNTS]), ['currencies']);

        $fresh = $this->database('psb_snapshot_fresh');
        $this->runner($fresh)->migrate(SnapshotPlans::plan([SnapshotPlans::NOTE]), $snapshot);

        $full = $this->database('psb_snapshot_full');
        $this->runner($full)->migrate(SnapshotPlans::plan(self::ALL));

        self::assertEquals(
            $this->runner($full)->dump(SnapshotPlans::plan(self::ALL), ['currencies']),
            $this->runner($fresh)->dump(SnapshotPlans::plan([SnapshotPlans::NOTE]), ['currencies']),
        );

        // Новая строка справочника получает тот же идентификатор, что и после обычных миграций.
        foreach ([$fresh, $full] as $db) {
            $db->execute('INSERT INTO currencies (code, title) VALUES (\'EUR\', \'Евро\')');
        }
        self::assertSame(
            $full->fetchOne("SELECT id FROM currencies WHERE code = 'EUR'"),
            $fresh->fetchOne("SELECT id FROM currencies WHERE code = 'EUR'"),
        );
    }

    private function database(string $name): Database
    {
        return IntegrationDatabases::mariadbDatabase($this->config($name));
    }

    private function config(string $name): array
    {
        $config = IntegrationDatabases::getDefaultConfigForDriver(DriversEnum::MARIADB);

        $config['connections']['main']['dsn']     = 'mariadb://root:root@mariadb:3306/' . $name;
        $config['connections']['main']['options'] = [PDO::ATTR_TIMEOUT => 2];

        return $config;
    }

    private function runner(Database $db): MigrationRunner
    {
        return new MigrationRunner($db->manager(), connectionName: 'main');
    }
}
