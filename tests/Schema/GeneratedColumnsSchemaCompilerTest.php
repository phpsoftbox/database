<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnBlueprint::class)]
#[CoversClass(MariaDbSchemaCompiler::class)]
#[CoversClass(MySqlSchemaCompiler::class)]
#[CoversClass(PostgresSchemaCompiler::class)]
#[CoversClass(SqliteSchemaCompiler::class)]
final class GeneratedColumnsSchemaCompilerTest extends TestCase
{
    #[Test]
    public function mariaDbCompilesStoredAndVirtualColumnsForCreateTable(): void
    {
        $table = new TableBlueprint('order_lines');

        $table->integer('price');
        $table->integer('quantity');
        $table->integer('total')->generatedAs('price * quantity');
        $table->boolean('has_discount')->nullable()->generatedAs('discount > 0', stored: false);

        self::assertSame(
            'CREATE TABLE `order_lines` ('
            . '`price` INT NOT NULL, '
            . '`quantity` INT NOT NULL, '
            . '`total` INT GENERATED ALWAYS AS (price * quantity) STORED, '
            . '`has_discount` TINYINT(1) GENERATED ALWAYS AS (discount > 0) VIRTUAL'
            . ')',
            new MariaDbSchemaCompiler()->compileCreateTable($table),
        );
    }

    #[Test]
    public function mariaDbCompilesGeneratedColumnForAddAndChange(): void
    {
        $add = new TableBlueprint('memberships');

        $add->boolean('active_marker')
                    ->nullable()
                    ->generatedAs('IF(deleted_datetime IS NULL, 1, NULL)', stored: false)
                    ->comment('Active membership marker')
                    ->after('deleted_datetime');

        self::assertSame([
            'ALTER TABLE `memberships` ADD COLUMN '
            . '`active_marker` TINYINT(1) GENERATED ALWAYS AS (IF(deleted_datetime IS NULL, 1, NULL)) '
            . "VIRTUAL COMMENT 'Active membership marker' AFTER `deleted_datetime`",
        ], new MariaDbSchemaCompiler()->compileAlterTableAddColumns($add));

        $change = new TableBlueprint('order_lines');

        $change->integer('total')->generatedAs('price * quantity * 100')->change();

        self::assertSame([
            'ALTER TABLE `order_lines` MODIFY COLUMN '
            . '`total` INT GENERATED ALWAYS AS (price * quantity * 100) STORED',
        ], new MariaDbSchemaCompiler()->compileAlterTableChangeColumns($change));
    }

    #[Test]
    public function mariaDbCompilesIndexOnGeneratedColumn(): void
    {
        $table = new TableBlueprint('memberships');

        $table->boolean('active_marker')
                    ->nullable()
                    ->generatedAs('IF(deleted_datetime IS NULL, 1, NULL)')
                    ->unique('memberships_active_unique');

        self::assertSame([
            'CREATE UNIQUE INDEX IF NOT EXISTS `memberships_active_unique` ON `memberships` (`active_marker`)',
        ], new MariaDbSchemaCompiler()->compileCreateIndexes($table));
    }

    #[Test]
    public function mysqlKeepsGeneratedNullabilityAndUsesMySqlIndexSyntax(): void
    {
        $table = new TableBlueprint('memberships');

        $table->boolean('active_marker')
                    ->generatedAs('IF(deleted_datetime IS NULL, 1, NULL)')
                    ->unique('memberships_active_unique');

        $compiler = new MySqlSchemaCompiler();

        self::assertSame(
            'CREATE TABLE `memberships` ('
            . '`active_marker` TINYINT(1) GENERATED ALWAYS AS '
            . '(IF(deleted_datetime IS NULL, 1, NULL)) STORED NOT NULL'
            . ')',
            $compiler->compileCreateTable($table),
        );
        self::assertSame([
            'CREATE UNIQUE INDEX `memberships_active_unique` ON `memberships` (`active_marker`)',
        ], $compiler->compileCreateIndexes($table));
    }

    #[Test]
    public function postgresCompilesStoredAndVirtualGeneratedColumns(): void
    {
        $stored = new TableBlueprint('order_lines');

        $stored->integer('price');
        $stored->integer('quantity');
        $stored->integer('total')->generatedAs('price * quantity');

        self::assertSame(
            'CREATE TABLE "order_lines" ('
            . '"price" INTEGER NOT NULL, '
            . '"quantity" INTEGER NOT NULL, '
            . '"total" INTEGER GENERATED ALWAYS AS (price * quantity) STORED NOT NULL'
            . ')',
            new PostgresSchemaCompiler()->compileCreateTable($stored),
        );

        $virtual = new TableBlueprint('order_lines');

        $virtual->integer('total')->nullable()->generatedAs('price * quantity', stored: false);

        self::assertSame([
            'ALTER TABLE "order_lines" ADD COLUMN '
            . '"total" INTEGER GENERATED ALWAYS AS (price * quantity) VIRTUAL',
        ], new PostgresSchemaCompiler()->compileAlterTableAddColumns($virtual));
    }

    #[Test]
    public function postgresCompilesGeneratedExpressionChange(): void
    {
        $table = new TableBlueprint('order_lines');

        $table->integer('total')->generatedAs('price * quantity * 100')->change();

        self::assertSame([
            'ALTER TABLE "order_lines" ALTER COLUMN "total" DROP DEFAULT',
            'ALTER TABLE "order_lines" ALTER COLUMN "total" TYPE INTEGER',
            'ALTER TABLE "order_lines" ALTER COLUMN "total" SET EXPRESSION AS (price * quantity * 100)',
            'ALTER TABLE "order_lines" ALTER COLUMN "total" SET NOT NULL',
        ], new PostgresSchemaCompiler()->compileAlterTableChangeColumns($table));
    }

    #[Test]
    public function generatedColumnRejectsDefaultIncludingExplicitNull(): void
    {
        foreach (['value', null] as $default) {
            $table = new TableBlueprint('metrics');

            $table->integer('calculated')->generatedAs('source * 2')->default($default);

            try {
                new MariaDbSchemaCompiler()->compileCreateTable($table);
                self::fail('Generated column with DEFAULT must be rejected.');
            } catch (ConfigurationException $e) {
                self::assertSame('Generated columns cannot have a default value.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function generatedColumnRejectsAutoIncrementAndCurrentTimestampModifiers(): void
    {
        $autoIncrement = new TableBlueprint('metrics');

        $autoIncrement->integer('calculated')->generatedAs('source * 2')->autoIncrement();

        $this->assertCompilationFails(
            $autoIncrement,
            'Generated columns cannot use auto increment.',
        );

        $current = new TableBlueprint('metrics');

        $current->datetime('calculated')->generatedAs('source_datetime')->useCurrentOnUpdate();

        $this->assertCompilationFails(
            $current,
            'Generated columns cannot use CURRENT_TIMESTAMP modifiers.',
        );
    }

    #[Test]
    public function generatedColumnRejectsEmptyExpression(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Generated column expression must be non-empty.');

        new ColumnBlueprint('calculated', 'integer')->generatedAs('   ');
    }

    #[Test]
    public function sqliteRejectsGeneratedColumnBeforeExecutingSql(): void
    {
        $connection = new SpyConnection(new FakePdo('sqlite'));

        $builder = new SchemaBuilder($connection, new SqliteSchemaCompiler());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Generated columns are not supported by sqlite schema compiler.');

        try {
            $builder->create('metrics', function (TableBlueprint $table): void {
                $table->integer('source');
                $table->integer('calculated')->generatedAs('source * 2');
            });
        } finally {
            self::assertSame([], $connection->executed);
        }
    }

    private function assertCompilationFails(TableBlueprint $table, string $message): void
    {
        try {
            new MariaDbSchemaCompiler()->compileCreateTable($table);
            self::fail('Generated column modifiers must be validated.');
        } catch (ConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
