<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqliteSchemaCompiler::class)]
#[CoversMethod(SqliteSchemaCompiler::class, 'compileAlterTableAddForeignKeys')]
#[CoversMethod(SqliteSchemaCompiler::class, 'compileAlterTableDropForeignKeys')]
final class SqliteAlterForeignKeyTest extends TestCase
{
    /**
     * Проверим, что добавление внешнего ключа в ALTER TABLE на SQLite выбрасывает исключение, а не пропускается.
     *
     * @see SqliteSchemaCompiler::compileAlterTableAddForeignKeys()
     */
    #[Test]
    public function rejectsAddingForeignKey(): void
    {
        $table = new TableBlueprint('profiles');

        $table->foreignKey(['user_id'], 'users', ['id']);

        $this->expectException(ConfigurationException::class);

        new SqliteSchemaCompiler()->compileAlterTableAddForeignKeys($table);
    }

    /**
     * Проверим, что удаление внешнего ключа в ALTER TABLE на SQLite выбрасывает исключение, а не пропускается.
     *
     * @see SqliteSchemaCompiler::compileAlterTableDropForeignKeys()
     */
    #[Test]
    public function rejectsDroppingForeignKey(): void
    {
        $table = new TableBlueprint('profiles');

        $table->dropForeignKey('profiles_user_id_fk');

        $this->expectException(ConfigurationException::class);

        new SqliteSchemaCompiler()->compileAlterTableDropForeignKeys($table);
    }

    /**
     * Проверим, что ALTER TABLE без операций с внешними ключами на SQLite не выбрасывает исключение.
     *
     * @see SqliteSchemaCompiler::compileAlterTableAddForeignKeys()
     */
    #[Test]
    public function returnsNothingWithoutForeignKeys(): void
    {
        self::assertSame([], new SqliteSchemaCompiler()->compileAlterTableAddForeignKeys(new TableBlueprint('profiles')));
    }
}
