<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Schema\MariaDbSchemaManager;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(MariaDbSchemaManager::class)]
final class MariaDbSchemaManagerTest extends TestCase
{
    /**
     * Интеграционный тест: проверяет, что MariaDbSchemaManager работает на реальной MariaDB из docker-compose.
     *
     * Тест пропускается, если расширение pdo_mysql недоступно.
     */
    #[Test]
    public function introspectsSchemaInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        // На всякий случай создаём таблицу
        $db->execute('DROP TABLE IF EXISTS users');
        $db->execute('DROP TABLE IF EXISTS user_groups');
        $db->execute('CREATE TABLE user_groups (id INT PRIMARY KEY AUTO_INCREMENT)');
        $db->execute('CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, group_id INT NOT NULL, name VARCHAR(50) NOT NULL, INDEX users_name_idx (name), CONSTRAINT users_group_fk FOREIGN KEY (group_id) REFERENCES user_groups(id))');

        $schema = $db->schema();
        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasColumn('users', 'id'));
        self::assertSame(['id'], $schema->primaryKey('users'));

        self::assertNotNull($schema->column('users', 'id'));
        self::assertNull($schema->column('users', 'missing'));
        self::assertTrue($schema->hasIndex('users', 'users_name_idx'));
        self::assertFalse($schema->hasIndex('users', 'missing_idx'));
        self::assertTrue($schema->hasForeignKey('users', 'users_group_fk'));
        self::assertFalse($schema->hasForeignKey('users', 'missing_fk'));
        self::assertNotNull($schema->foreignKey('users', 'users_group_fk'));

        $missing = $schema->missingColumns('users', ['id', 'name', 'deleted_datetime']);
        self::assertSame(['deleted_datetime'], $missing->all());
        self::assertTrue($missing->has('deleted_datetime'));

        $cols = $schema->columns('users');
        self::assertNotEmpty($cols);
    }
}
