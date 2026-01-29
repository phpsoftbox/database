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
        $db->execute('CREATE TABLE users (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(50) NOT NULL)');

        $schema = $db->schema();
        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasColumn('users', 'id'));
        self::assertSame(['id'], $schema->primaryKey('users'));

        self::assertNotNull($schema->column('users', 'id'));
        self::assertNull($schema->column('users', 'missing'));

        $cols = $schema->columns('users');
        self::assertNotEmpty($cols);
    }
}
