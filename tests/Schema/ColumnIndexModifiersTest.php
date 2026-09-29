<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(ColumnBlueprint::class)]
#[CoversClass(AbstractSchemaCompiler::class)]
#[CoversMethod(ColumnBlueprint::class, 'unique')]
#[CoversMethod(ColumnBlueprint::class, 'index')]
#[CoversMethod(AbstractSchemaCompiler::class, 'compileCreateIndexes')]
final class ColumnIndexModifiersTest extends TestCase
{
    /**
     * Проверим, что unique() без имени создаёт уникальный индекс с именем по умолчанию.
     *
     * @see ColumnBlueprint::unique()
     * @see AbstractSchemaCompiler::compileCreateIndexes()
     */
    #[Test]
    public function uniqueWithoutNameUsesDefaultIndexName(): void
    {
        $table = new TableBlueprint('users');

        $table->string('email')->unique();

        self::assertSame(
            ['CREATE UNIQUE INDEX IF NOT EXISTS "users_email_unique" ON "users" ("email")'],
            new PostgresSchemaCompiler()->compileCreateIndexes($table),
        );
    }

    /**
     * Проверим, что index() без имени создаёт обычный индекс с именем по умолчанию.
     *
     * @see ColumnBlueprint::index()
     * @see AbstractSchemaCompiler::compileCreateIndexes()
     */
    #[Test]
    public function indexWithoutNameUsesDefaultIndexName(): void
    {
        $table = new TableBlueprint('users');

        $table->string('name')->index();

        self::assertSame(
            ['CREATE INDEX IF NOT EXISTS "users_name_index" ON "users" ("name")'],
            new PostgresSchemaCompiler()->compileCreateIndexes($table),
        );
    }

    /**
     * Проверим, что пример из документации создаёт уникальный индекс в SQLite.
     *
     * @see SchemaBuilder::create()
     */
    #[Test]
    public function uniqueColumnIsEnforcedInSqlite(): void
    {
        self::assertUniqueEnforced(IntegrationDatabases::sqliteDatabase());
    }

    /**
     * Проверим, что пример из документации создаёт уникальный индекс в PostgreSQL.
     *
     * @see SchemaBuilder::create()
     */
    #[Test]
    public function uniqueColumnIsEnforcedInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertUniqueEnforced($db);
    }

    /**
     * Проверим, что пример из документации создаёт уникальный индекс в MariaDB.
     *
     * @see SchemaBuilder::create()
     */
    #[Test]
    public function uniqueColumnIsEnforcedInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertUniqueEnforced($db);
    }

    private static function assertUniqueEnforced(Database $db): void
    {
        $conn = $db->connection();
        $conn->schema()->dropIfExists('psb_unique_users');

        try {
            $conn->schema()->create('psb_unique_users', static function (TableBlueprint $table): void {
                $table->id();
                $table->string('email')->unique();
                $table->string('name')->index();
            });

            self::assertTrue($db->schema()->hasIndex('psb_unique_users', 'psb_unique_users_email_unique'));
            self::assertTrue($db->schema()->hasIndex('psb_unique_users', 'psb_unique_users_name_index'));

            $conn->query()->insert('psb_unique_users', ['email' => 'a@example.test', 'name' => 'A'])->execute();

            try {
                $conn->query()->insert('psb_unique_users', ['email' => 'a@example.test', 'name' => 'B'])->execute();
                self::fail('Duplicate email must violate the unique index.');
            } catch (QueryException) {
                // Ожидаемое нарушение уникальности.
            }
        } finally {
            $conn->schema()->dropIfExists('psb_unique_users');
        }
    }
}
