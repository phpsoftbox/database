<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\CharsetCollationName;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharsetCollationName::class)]
#[CoversClass(ColumnBlueprint::class)]
#[CoversClass(TableBlueprint::class)]
#[CoversMethod(CharsetCollationName::class, 'normalize')]
final class CharsetCollationValidationTest extends TestCase
{
    /**
     * Проверяет отказ для пустых имён, кавычек, управляющих символов и SQL-фрагментов в fluent API.
     *
     * @see ColumnBlueprint::charset()
     * @see TableBlueprint::collation()
     */
    #[Test]
    #[DataProvider('invalidNames')]
    public function settersRejectInvalidNames(string $target, string $option, string $value): void
    {
        $blueprint = $target === 'table' ? new TableBlueprint('codes') : new ColumnBlueprint('serial', 'string');
        $this->expectException(ConfigurationException::class);
        $blueprint->{$option}($value);
    }

    /**
     * Проверяет повторную валидацию публичных свойств таблицы и колонки обоими компиляторами.
     *
     * @see MySqlSchemaCompiler::compileCreateTable()
     * @see MariaDbSchemaCompiler::compileCreateTable()
     */
    #[Test]
    #[DataProvider('invalidPublicProperties')]
    public function compilerRejectsInvalidPublicProperties(string $dialect, string $target, string $option, string $value): void
    {
        $table = new TableBlueprint('codes');

        $column               = $table->string('serial');
        $blueprint            = $target === 'table' ? $table : $column;
        $blueprint->{$option} = $value;
        $compiler             = $dialect === 'mysql' ? new MySqlSchemaCompiler() : new MariaDbSchemaCompiler();

        $this->expectException(ConfigurationException::class);
        $compiler->compileCreateTable($table);
    }

    /**
     * Проверяет нормализацию краевых пробелов без замены или угадывания имени collation.
     *
     * @see CharsetCollationName::normalize()
     */
    #[Test]
    public function namesAreTrimmedButNotReplaced(): void
    {
        self::assertSame('utf8mb4', new ColumnBlueprint('serial', 'string')->charset(' utf8mb4 ')->charset);
        self::assertSame('utf8mb4_bin', new TableBlueprint('codes')->collation(' utf8mb4_bin ')->collation);
        self::assertSame('Unknown_Server_Collation', CharsetCollationName::normalize('Unknown_Server_Collation', 'collation'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidNames(): iterable
    {
        foreach (['table', 'column'] as $target) {
            foreach (['charset', 'collation'] as $option) {
                foreach (['', '   ', 'utf8mb4 bin', 'utf8mb4`bin', "utf8mb4'bin", 'utf8mb4; DROP TABLE codes', 'utf8mb4/*comment*/', 'utf8mb4--comment', "utf8mb4\0bin", "utf8mb4\nbin", "utf8mb4\0", "utf8mb4\n", "\tutf8mb4", 'utf8mb4.utf8mb4_bin', 'кодировка'] as $index => $value) {
                    yield "$target/$option/$index" => [$target, $option, $value];
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidPublicProperties(): iterable
    {
        foreach (['mysql', 'mariadb'] as $dialect) {
            foreach (self::invalidNames() as $name => $arguments) {
                yield "$dialect/$name" => [$dialect, ...$arguments];
            }
        }
    }
}
