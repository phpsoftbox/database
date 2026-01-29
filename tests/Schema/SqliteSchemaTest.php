<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Schema\ColumnDefinition;
use PhpSoftBox\Database\Schema\IndexDefinition;
use PhpSoftBox\Database\Schema\SchemaManagerFactory;
use PhpSoftBox\Database\Schema\SqliteSchemaManager;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_filter;

#[CoversClass(Database::class)]
#[CoversClass(SchemaManagerFactory::class)]
#[CoversClass(SqliteSchemaManager::class)]
#[CoversClass(ColumnDefinition::class)]
final class SqliteSchemaTest extends TestCase
{
    /**
     * Проверяет, что SchemaManager для sqlite умеет перечислять таблицы и колонки.
     */
    #[Test]
    public function listsTablesAndColumns(): void
    {
        try {
            $db = IntegrationDatabases::sqliteDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $db->execute('PRAGMA foreign_keys = ON');

        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age INTEGER NULL)');
        $db->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id))');
        $db->execute('CREATE INDEX idx_posts_user_id ON posts(user_id)');

        $schema = $db->schema();

        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasTable('posts'));
        self::assertFalse($schema->hasTable('missing'));

        $tables = $schema->tables();
        self::assertContains('users', $tables);
        self::assertContains('posts', $tables);

        self::assertTrue($schema->hasColumn('users', 'id'));
        self::assertTrue($schema->hasColumn('users', 'name'));
        self::assertFalse($schema->hasColumn('users', 'missing'));

        $pk = $schema->primaryKey('users');
        self::assertSame(['id'], $pk);

        $indexes = $schema->indexes('posts');
        self::assertNotEmpty($indexes);
        self::assertTrue(
            (bool) array_filter($indexes, static fn ($i): bool => $i instanceof IndexDefinition && $i->name === 'idx_posts_user_id'),
        );

        $fks = $schema->foreignKeys('posts');
        self::assertNotEmpty($fks);

        // helpers
        self::assertNotNull($schema->column('users', 'id'));
        self::assertNull($schema->column('users', 'missing'));

        self::assertNotNull($schema->index('posts', 'idx_posts_user_id'));
        self::assertNull($schema->index('posts', 'missing_idx'));

        $fkByCol = $schema->foreignKeysByColumn('posts', 'user_id');
        self::assertNotEmpty($fkByCol);

        $table = $schema->table('users');
        self::assertSame('users', $table->name);
        self::assertNotEmpty($table->columns);
    }
}
