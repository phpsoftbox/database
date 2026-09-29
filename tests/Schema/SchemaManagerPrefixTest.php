<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Schema\SqliteSchemaManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteSchemaManager::class)]
#[CoversMethod(SqliteSchemaManager::class, 'hasTable')]
#[CoversMethod(SqliteSchemaManager::class, 'table')]
final class SchemaManagerPrefixTest extends TestCase
{
    /**
     * Проверим, что SchemaManager принимает логическое имя таблицы и сам применяет prefix подключения,
     * как SchemaBuilder.
     *
     * @see SqliteSchemaManager::hasTable()
     * @see SqliteSchemaManager::table()
     */
    #[Test]
    public function appliesConnectionPrefixToTableNames(): void
    {
        $db = Database::fromConfig([
            'connections' => [
                'default' => 'main',
                'main'    => ['dsn' => 'sqlite:///:memory:', 'prefix' => 't_'],
            ],
        ]);

        $db->connection()->schema()->create('users', static function (TableBlueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        $schema = $db->schema();

        self::assertTrue($schema->hasTable('users'));
        self::assertTrue($schema->hasColumn('users', 'email'));
        self::assertTrue($schema->hasIndex('users', 't_users_email_unique'));
        self::assertSame('t_users', $schema->table('users')->name);
        self::assertSame(['t_users'], $schema->tables());
    }
}
