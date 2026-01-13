<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\SchemaBuilder\UseCurrentFormatsEnum;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(SqliteSchemaCompiler::class)]
final class SchemaBuilderSqlGenerationTest extends TestCase
{
    /**
     * Проверяет, что SchemaBuilder::create() выполняет SQL, сгенерированный компилятором,
     * и по умолчанию использует IF NOT EXISTS.
     */
    #[Test]
    public function createExecutesGeneratedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->create('users', function (TableBlueprint $t): void {
            $t->id();
            $t->text('name')->nullable(true);
        });

        self::assertCount(1, $conn->executed);
        self::assertSame('CREATE TABLE IF NOT EXISTS "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT)', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что SchemaBuilder::create() может быть вызван без IF NOT EXISTS.
     */
    #[Test]
    public function createWithoutIfNotExistsExecutesGeneratedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->create('users', function (TableBlueprint $t): void {
            $t->id();
            $t->text('name');
        }, false);

        self::assertCount(1, $conn->executed);
        self::assertSame('CREATE TABLE "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL)', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что SchemaBuilder::alterTable() выполняет SQL для добавления колонок.
     */
    #[Test]
    public function alterTableExecutesAddColumnSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->alterTable('users', function (TableBlueprint $t): void {
            $t->text('email')->nullable(true);
        });

        self::assertCount(1, $conn->executed);
        self::assertSame('ALTER TABLE "users" ADD COLUMN "email" TEXT', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что SchemaBuilder::addColumn() является обёрткой над alterTable().
     */
    #[Test]
    public function addColumnExecutesAlterTable(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->addColumn('users', function (TableBlueprint $t): void {
            $t->integer('age')->nullable();
        });

        self::assertCount(1, $conn->executed);
        self::assertSame('ALTER TABLE "users" ADD COLUMN "age" INTEGER', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что SchemaBuilder::renameTable() выполняет корректный SQL.
     */
    #[Test]
    public function renameTableExecutesSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->renameTable('users', 'people');

        self::assertCount(1, $conn->executed);
        self::assertSame('ALTER TABLE "users" RENAME TO "people"', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что SchemaBuilder::dropIfExists() выполняет корректный SQL.
     */
    #[Test]
    public function dropIfExistsExecutesSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->dropIfExists('users');

        self::assertCount(1, $conn->executed);
        self::assertSame('DROP TABLE IF EXISTS "users"', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет генерацию SQL для таблицы MariaDB с модификаторами таблицы.
     */
    #[Test]
    public function mariaDbCreateTableIncludesTableModifiers(): void
    {
        $t = new TableBlueprint('users');
        $t->engine('InnoDB')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->comment('Users')->temporary(true);
        $t->id();

        $sql = (new MariaDbSchemaCompiler())->compileCreateTable($t);

        self::assertStringStartsWith('CREATE TEMPORARY TABLE `users`', $sql);
        self::assertStringContainsString('ENGINE=InnoDB', $sql);
        self::assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        self::assertStringContainsString('COLLATE=utf8mb4_unicode_ci', $sql);
        self::assertStringContainsString("COMMENT='Users'", $sql);
    }

    /**
     * Проверяет, что MariaDB ALTER TABLE ADD COLUMN поддерживает FIRST/AFTER.
     */
    #[Test]
    public function mariaDbAlterTableSupportsFirstAfter(): void
    {
        $t = new TableBlueprint('users');
        $t->text('b')->after('a');

        $sqlList = (new MariaDbSchemaCompiler())->compileAlterTableAddColumns($t);
        self::assertCount(1, $sqlList);
        self::assertSame('ALTER TABLE `users` ADD COLUMN `b` TEXT NOT NULL AFTER `a`', $sqlList[0]);

        $t2 = new TableBlueprint('users');
        $t2->text('c')->first();
        $sqlList2 = (new MariaDbSchemaCompiler())->compileAlterTableAddColumns($t2);
        self::assertSame('ALTER TABLE `users` ADD COLUMN `c` TEXT NOT NULL FIRST', $sqlList2[0]);
    }

    /**
     * Проверяет, что MariaDB useCurrent/useCurrentOnUpdate работает для TIMESTAMP при соответствующем формате.
     */
    #[Test]
    public function mariaDbUseCurrentForTimestamp(): void
    {
        $t = new TableBlueprint('events');
        $t->timestamp('updated_at')
            ->useCurrent(true, UseCurrentFormatsEnum::TIMESTAMP)
            ->useCurrentOnUpdate(true, UseCurrentFormatsEnum::TIMESTAMP);

        $sql = (new MariaDbSchemaCompiler())->compileCreateTable($t);
        self::assertStringContainsString('`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    /**
     * Проверяет, что SchemaBuilder::create() выполняет уже два SQL, если в blueprint описан индекс.
     */
    #[Test]
    public function createExecutesIndexSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->create('users', function (TableBlueprint $t): void {
            $t->id();
            $t->string('email', 255)->unique('users_email_unique');
        });

        self::assertCount(2, $conn->executed);
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "email" TEXT NOT NULL)',
            $conn->executed[0]['sql'],
        );
        self::assertSame(
            'CREATE UNIQUE INDEX IF NOT EXISTS "users_email_unique" ON "users" ("email")',
            $conn->executed[1]['sql'],
        );
    }

    /**
     * Проверяет, что SchemaBuilder::createIfNotExists() является обёрткой над create(..., true).
     */
    #[Test]
    public function createIfNotExistsExecutesGeneratedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));
        $builder = new SchemaBuilder($conn, new SqliteSchemaCompiler());

        $builder->createIfNotExists('users', function (TableBlueprint $t): void {
            $t->id();
            $t->text('name');
        });

        self::assertCount(1, $conn->executed);
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS "users" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL)',
            $conn->executed[0]['sql'],
        );
    }
}
