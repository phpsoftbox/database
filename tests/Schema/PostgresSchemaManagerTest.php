<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Schema\PostgresSchemaManager;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(PostgresSchemaManager::class)]
final class PostgresSchemaManagerTest extends TestCase
{
    /**
     * Интеграционный тест: проверяет, что PostgresSchemaManager работает на реальном Postgres из docker-compose
     * и корректно отдаёт таблицы/колонки/PK/индексы/FK, включая helper-методы (column/index/foreignKeysByColumn).
     */
    #[Test]
    public function introspectsSchemaInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        // Подготовка схемы.
        $db->execute('DROP TABLE IF EXISTS orders');
        $db->execute('DROP TABLE IF EXISTS users');

        $db->execute('CREATE TABLE users (id SERIAL PRIMARY KEY, email TEXT NOT NULL)');
        $db->execute('CREATE UNIQUE INDEX users_email_uq ON users(email)');

        $db->execute('CREATE TABLE orders (id SERIAL PRIMARY KEY, user_id INT NOT NULL, total INT NOT NULL)');
        $db->execute('ALTER TABLE orders ADD CONSTRAINT orders_user_fk FOREIGN KEY (user_id) REFERENCES users(id)');

        $schema = $db->schema();

        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('orders'));

        self::assertTrue($schema->hasColumn('users', 'id'));
        self::assertTrue($schema->hasColumn('users', 'email'));
        self::assertSame(['id'], $schema->primaryKey('users'));

        $colEmail = $schema->column('users', 'email');
        self::assertNotNull($colEmail);
        self::assertSame('email', $colEmail->name);

        $idx = $schema->index('users', 'users_email_uq');
        self::assertNotNull($idx);
        self::assertTrue($idx->unique);
        self::assertSame(['email'], $idx->columns);

        $fks = $schema->foreignKeys('orders');
        self::assertNotEmpty($fks);

        $byColumn = $schema->foreignKeysByColumn('orders', 'user_id');
        self::assertNotEmpty($byColumn);
        self::assertSame('users', $byColumn[0]->table);
        self::assertSame('user_id', $byColumn[0]->from);
        self::assertSame('id', $byColumn[0]->to);

        $table = $schema->table('orders');
        self::assertSame('orders', $table->name);
        self::assertNotEmpty($table->columns);
        self::assertNotEmpty($table->foreignKeys);
    }
}
