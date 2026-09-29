<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PhpSoftBox\Database\Migrations\MigrationPlan;
use PhpSoftBox\Database\Migrations\MigrationRunner;
use PhpSoftBox\Database\Migrations\SqlMigrationRepository;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PhpSoftBox\Database\Tests\Utils\NoopMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(SqlMigrationRepository::class)]
#[CoversMethod(MigrationRunner::class, 'migrate')]
final class MigrationRunnerMariaDbTest extends TestCase
{
    /**
     * Проверим, что на MariaDB повторные запуски migrate() не падают на создании таблицы миграций и её индексов.
     *
     * @see MigrationRunner::migrate()
     */
    #[Test]
    public function repeatedMigrateRunsSucceed(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $db->execute('DROP TABLE IF EXISTS psb_test_migrations');

        try {
            $plan = new MigrationPlan()
                ->add('20260101000000_first', new NoopMigration())
                ->add('20260101000100_second', new NoopMigration());

            $first = new MigrationRunner($db->manager(), new SqlMigrationRepository('psb_test_migrations'));

            self::assertSame(['20260101000000_first', '20260101000100_second'], $first->migrate($plan));

            // Новый runner и репозиторий: таблица уже существует, ensureTable() не должен падать.
            $second = new MigrationRunner($db->manager(), new SqlMigrationRepository('psb_test_migrations'));

            self::assertSame([], $second->migrate($plan));
        } finally {
            $db->execute('DROP TABLE IF EXISTS psb_test_migrations');
        }
    }
}
