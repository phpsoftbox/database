<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractMySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractMySqlSchemaCompiler::class)]
#[CoversClass(ColumnBlueprint::class)]
#[CoversMethod(AbstractMySqlSchemaCompiler::class, 'compileAlterTableChangeColumns')]
final class AutoIncrementSchemaCompilerTest extends TestCase
{
    /**
     * Изменение id сохраняет AUTO_INCREMENT и комментарий, но не пытается создать PRIMARY KEY повторно.
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     * @see TableBlueprint::id()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function changingIdDoesNotAddPrimaryKey(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->id()->comment('Record identifier')->change()->first();

        self::assertSame([
            "ALTER TABLE `records` MODIFY COLUMN `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Record identifier' FIRST",
        ], $compiler->compileAlterTableChangeColumns($table));
        self::assertSame([], $compiler->compileAlterTableAddColumns($table));
    }

    /**
     * Явный autoIncrement у BIGINT включается в MODIFY вместе с unsigned, комментарием и положением колонки.
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     * @see ColumnBlueprint::autoIncrement()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function changingBigIntegerIncludesAutoIncrement(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->bigInteger('record_id')->unsigned()->autoIncrement()->comment('Record identifier')->after('name')->change();

        self::assertSame([
            "ALTER TABLE `records` MODIFY COLUMN `record_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Record identifier' AFTER `name`",
        ], $compiler->compileAlterTableChangeColumns($table));
    }

    /**
     * Модификатор autoIncrement также работает для обычного INT и не добавляет индекс автоматически.
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     * @see ColumnBlueprint::autoIncrement()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function changingIntegerIncludesAutoIncrement(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->integer('id')->autoIncrement()->change();

        self::assertSame([
            'ALTER TABLE `records` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT',
        ], $compiler->compileAlterTableChangeColumns($table));
    }

    /**
     * Обычное изменение BIGINT без autoIncrement не добавляет этот атрибут неявно.
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function changingPlainBigIntegerDoesNotInferAutoIncrement(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->bigInteger('id')->change();

        self::assertSame(['ALTER TABLE `records` MODIFY COLUMN `id` BIGINT NOT NULL'], $compiler->compileAlterTableChangeColumns($table));
    }

    /**
     * CREATE TABLE с id по-прежнему создаёт первичный ключ и автоинкремент.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     * @see TableBlueprint::id()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function creatingIdStillAddsPrimaryKey(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->id()->comment('Record identifier');

        self::assertSame("CREATE TABLE `records` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Record identifier')", $compiler->compileCreateTable($table));
    }

    /**
     * ADD COLUMN с новым id сохраняет создание первичного ключа, в отличие от change().
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableAddColumns()
     * @see TableBlueprint::id()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function addingIdStillAddsPrimaryKey(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->id('record_id');

        self::assertSame(['ALTER TABLE `records` ADD COLUMN `record_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'], $compiler->compileAlterTableAddColumns($table));
    }

    /**
     * Явный автоинкремент включается в CREATE TABLE без навязывания PRIMARY KEY.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     * @see ColumnBlueprint::autoIncrement()
     */
    #[Test]
    #[DataProvider('compilers')]
    public function creatingBigIntegerIncludesExplicitAutoIncrement(AbstractMySqlSchemaCompiler $compiler): void
    {
        $table = new TableBlueprint('records');

        $table->bigInteger('sequence')->autoIncrement()->comment('Sequence number');

        self::assertSame("CREATE TABLE `records` (`sequence` BIGINT NOT NULL AUTO_INCREMENT COMMENT 'Sequence number')", $compiler->compileCreateTable($table));
    }

    /** @return iterable<string, array{AbstractMySqlSchemaCompiler}> */
    public static function compilers(): iterable
    {
        yield 'MariaDB' => [new MariaDbSchemaCompiler()];
        yield 'MySQL' => [new MySqlSchemaCompiler()];
    }
}
