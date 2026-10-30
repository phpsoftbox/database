<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableBlueprint::class)]
#[CoversMethod(TableBlueprint::class, 'mediumText')]
#[CoversMethod(TableBlueprint::class, 'longText')]
final class LongTextColumnsTest extends TestCase
{
    /**
     * Проверим, что MEDIUMTEXT и LONGTEXT создаются в MariaDB с кодировкой и комментарием.
     *
     * @see TableBlueprint::mediumText()
     * @see TableBlueprint::longText()
     */
    #[Test]
    public function compilesMariaDbTypes(): void
    {
        $conn = new SpyConnection(new FakePdo('mariadb'));

        new SchemaBuilder($conn, new MariaDbSchemaCompiler())->create('documents', static function (TableBlueprint $table): void {
            $table->mediumText('summary')->comment('Краткое содержание');
            $table->longText('body')->nullable()->charset('utf8mb4')->comment('Текст');
        }, false);

        self::assertSame(
            "CREATE TABLE `documents` (`summary` MEDIUMTEXT NOT NULL COMMENT 'Краткое содержание', `body` LONGTEXT CHARACTER SET `utf8mb4` COMMENT 'Текст')",
            $conn->executed[0]['sql'],
        );
    }

    /**
     * Проверим, что в SQLite и Postgres длинный текст остаётся TEXT.
     *
     * @see TableBlueprint::longText()
     */
    #[Test]
    public function compilesTextForSqliteAndPostgres(): void
    {
        $definition = static function (TableBlueprint $table): void {
            $table->mediumText('summary');
            $table->longText('body');
        };
        $sqlite   = new SpyConnection(new FakePdo('sqlite'));
        $postgres = new SpyConnection(new FakePdo('pgsql'));

        new SchemaBuilder($sqlite, new SqliteSchemaCompiler())->create('documents', $definition, false);
        new SchemaBuilder($postgres, new PostgresSchemaCompiler())->create('documents', $definition, false);

        self::assertSame('CREATE TABLE "documents" ("summary" TEXT NOT NULL, "body" TEXT NOT NULL)', $sqlite->executed[0]['sql']);
        self::assertSame('CREATE TABLE "documents" ("summary" TEXT NOT NULL, "body" TEXT NOT NULL)', $postgres->executed[0]['sql']);
    }
}
