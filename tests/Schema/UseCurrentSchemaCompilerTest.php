<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractSchemaCompiler::class)]
#[CoversClass(MySqlSchemaCompiler::class)]
#[CoversClass(PostgresSchemaCompiler::class)]
#[CoversClass(SqliteSchemaCompiler::class)]
#[CoversMethod(ColumnBlueprint::class, 'useCurrent')]
#[CoversMethod(ColumnBlueprint::class, 'useCurrentOnUpdate')]
final class UseCurrentSchemaCompilerTest extends TestCase
{
    /**
     * Проверим, что на MySQL useCurrent() без аргументов применяется и к колонке timestamp.
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function mySqlAppliesUseCurrentToTimestamp(): void
    {
        $table = new TableBlueprint('events');

        $table->timestamp('created_at')->useCurrent();

        self::assertStringContainsString(
            '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            new MySqlSchemaCompiler()->compileCreateTable($table),
        );
    }

    /**
     * Проверим, что на PostgreSQL useCurrent() компилируется в DEFAULT CURRENT_TIMESTAMP.
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function postgresAppliesUseCurrent(): void
    {
        $table = new TableBlueprint('events');

        $table->datetime('created_at')->useCurrent();

        self::assertStringContainsString(
            '"created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            new PostgresSchemaCompiler()->compileCreateTable($table),
        );
    }

    /**
     * Проверим, что на SQLite useCurrent() компилируется в DEFAULT CURRENT_TIMESTAMP.
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function sqliteAppliesUseCurrent(): void
    {
        $table = new TableBlueprint('events');

        $table->datetime('created_at')->useCurrent();

        self::assertStringContainsString(
            '"created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP',
            new SqliteSchemaCompiler()->compileCreateTable($table),
        );
    }

    /**
     * Проверим, что при изменении колонки на PostgreSQL useCurrent() выставляет SET DEFAULT CURRENT_TIMESTAMP.
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function postgresChangeColumnSetsCurrentTimestampDefault(): void
    {
        $table = new TableBlueprint('events');

        $table->datetime('created_at')->useCurrent()->change();

        self::assertContains(
            'ALTER TABLE "events" ALTER COLUMN "created_at" SET DEFAULT CURRENT_TIMESTAMP',
            new PostgresSchemaCompiler()->compileAlterTableChangeColumns($table),
        );
    }

    /**
     * Проверим, что useCurrentOnUpdate() на PostgreSQL выбрасывает исключение, а не игнорируется.
     *
     * @see ColumnBlueprint::useCurrentOnUpdate()
     */
    #[Test]
    public function postgresRejectsUseCurrentOnUpdate(): void
    {
        $table = new TableBlueprint('events');

        $table->datetime('updated_at')->useCurrentOnUpdate();

        $this->expectException(ConfigurationException::class);

        new PostgresSchemaCompiler()->compileCreateTable($table);
    }

    /**
     * Проверим, что useCurrentOnUpdate() на SQLite выбрасывает исключение, а не игнорируется.
     *
     * @see ColumnBlueprint::useCurrentOnUpdate()
     */
    #[Test]
    public function sqliteRejectsUseCurrentOnUpdate(): void
    {
        $table = new TableBlueprint('events');

        $table->datetime('updated_at')->useCurrentOnUpdate();

        $this->expectException(ConfigurationException::class);

        new SqliteSchemaCompiler()->compileCreateTable($table);
    }

    /**
     * Проверим, что useCurrent() на колонке не datetime/timestamp выбрасывает исключение.
     *
     * @see ColumnBlueprint::useCurrent()
     */
    #[Test]
    public function rejectsUseCurrentOnNonTemporalColumn(): void
    {
        $table = new TableBlueprint('events');

        $table->string('title')->useCurrent();

        $this->expectException(ConfigurationException::class);

        new MySqlSchemaCompiler()->compileCreateTable($table);
    }
}
