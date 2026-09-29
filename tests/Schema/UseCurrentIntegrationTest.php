<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ColumnBlueprint::class)]
#[CoversMethod(ColumnBlueprint::class, 'useCurrent')]
final class UseCurrentIntegrationTest extends TestCase
{
    /**
     * Проверим, что в SQLite INSERT без значения заполняет NOT NULL-колонку с useCurrent().
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function fillsCurrentTimestampInSqlite(): void
    {
        self::assertInsertFillsTimestamps(IntegrationDatabases::sqliteDatabase());
    }

    /**
     * Проверим, что в PostgreSQL INSERT без значения заполняет NOT NULL-колонку с useCurrent().
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function fillsCurrentTimestampInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertInsertFillsTimestamps($db);
    }

    /**
     * Проверим, что в MariaDB INSERT без значения заполняет NOT NULL-колонки datetime и timestamp с useCurrent().
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function fillsCurrentTimestampInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertInsertFillsTimestamps($db);
    }

    private static function assertInsertFillsTimestamps(Database $db): void
    {
        $conn = $db->connection();
        $conn->schema()->dropIfExists('psb_use_current');

        try {
            $conn->schema()->create('psb_use_current', static function (TableBlueprint $table): void {
                $table->id();
                $table->string('title');
                $table->datetime('created_datetime')->useCurrent();
                $table->timestamp('created_timestamp')->useCurrent();
            });

            $conn->query()->insert('psb_use_current', ['title' => 'first'])->execute();

            $row = $conn->fetchOne('SELECT created_datetime, created_timestamp FROM ' . $conn->table('psb_use_current'));

            self::assertNotEmpty($row['created_datetime'] ?? null);
            self::assertNotEmpty($row['created_timestamp'] ?? null);
        } finally {
            $conn->schema()->dropIfExists('psb_use_current');
        }
    }
}
