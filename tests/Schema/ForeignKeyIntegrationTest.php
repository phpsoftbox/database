<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversNothing]
final class ForeignKeyIntegrationTest extends TestCase
{
    /**
     * Интеграционный тест: проверяет, что внешние ключи работают в MariaDB.
     */
    #[Test]
    public function foreignKeysEnforcedInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runForeignKeyScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет, что внешние ключи работают в Postgres.
     */
    #[Test]
    public function foreignKeysEnforcedInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runForeignKeyScenario($db, driver: 'postgres');
    }

    private static function runForeignKeyScenario(Database $db, string $driver): void
    {
        $builder = new SchemaBuilderFactory()->create($db->connection());

        try {
            $db->execute('DROP TABLE IF EXISTS children');
            $db->execute('DROP TABLE IF EXISTS parents');

            $builder->create('parents', function (TableBlueprint $table) use ($driver): void {
                if ($driver === 'mariadb') {
                    $table->engine('InnoDB');
                }
                $table->id();
                $table->string('name');
            });

            $builder->create('children', function (TableBlueprint $table) use ($driver): void {
                if ($driver === 'mariadb') {
                    $table->engine('InnoDB');
                }
                $table->id();
                $table->foreignId('parent_id');
                $table->string('name');
                $table->foreignKey(['parent_id'], 'parents', ['id'])->onDelete('cascade');
            });

            $db->execute('INSERT INTO parents (name) VALUES (:name)', ['name' => 'Parent']);
            $db->execute('INSERT INTO children (parent_id, name) VALUES (:parent_id, :name)', [
                'parent_id' => 1,
                'name'      => 'Child',
            ]);

            $thrown = false;
            try {
                $db->execute('INSERT INTO children (parent_id, name) VALUES (:parent_id, :name)', [
                    'parent_id' => 999,
                    'name'      => 'Invalid',
                ]);
            } catch (Throwable $e) {
                $thrown = true;
            }

            self::assertTrue($thrown, 'Foreign key constraint should reject invalid parent_id.');
        } finally {
            $db->execute('DROP TABLE IF EXISTS children');
            $db->execute('DROP TABLE IF EXISTS parents');
        }
    }
}
