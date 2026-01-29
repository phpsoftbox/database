<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractMySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\PostgresSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SchemaCompilerInterface;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;

#[CoversClass(AbstractMySqlSchemaCompiler::class)]
#[CoversClass(PostgresSchemaCompiler::class)]
#[CoversClass(SqliteSchemaCompiler::class)]
#[CoversMethod(MySqlSchemaCompiler::class, 'compileCreateTable')]
#[CoversMethod(MariaDbSchemaCompiler::class, 'compileAlterTableChangeColumns')]
final class ColumnCharacterOptionsCompilerTest extends TestCase
{
    /**
     * Проверяет точный SQL string/text с опциями отдельно, вместе и без них во всех операциях.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     * @see AbstractMySqlSchemaCompiler::compileAlterTableAddColumns()
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     */
    #[Test]
    #[DataProvider('supportedCases')]
    public function compilesCharacterOptions(string $dialect, string $operation, string $type, ?string $charset, ?string $collation): void
    {
        $table = new TableBlueprint('codes');

        $column            = $type === 'string' ? $table->string('serial', 64) : $table->text('serial');
        $column->charset   = $charset;
        $column->collation = $collation;
        $column->nullable()->default(null)->comment("Seller's code");
        $column->change($operation === 'change');

        $definition = '`serial` ' . ($type === 'string' ? 'VARCHAR(64)' : 'TEXT');
        if ($charset !== null) {
            $definition .= ' CHARACTER SET `utf8mb4`';
        }
        if ($collation !== null) {
            $definition .= ' COLLATE `utf8mb4_bin`';
        }
        $definition .= " DEFAULT NULL COMMENT 'Seller''s code'";
        $expected = match ($operation) {
            'create' => 'CREATE TABLE `codes` (' . $definition . ')',
            'add'    => 'ALTER TABLE `codes` ADD COLUMN ' . $definition,
            'change' => 'ALTER TABLE `codes` MODIFY COLUMN ' . $definition,
        };

        self::assertSame($expected, $this->compile($this->compiler($dialect), $operation, $table));
    }

    /**
     * Проверяет сохранение табличных значений и отсутствие опций у соседней колонки.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     */
    #[Test]
    #[DataProvider('mysqlDialects')]
    public function columnOverridesOnlyItsOwnCollation(string $dialect): void
    {
        $table = new TableBlueprint('codes');

        $table->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
        $table->string('serial', 64)->charset('utf8mb4')->collation('utf8mb4_bin');
        $table->string('label');

        self::assertSame(
            'CREATE TABLE `codes` (`serial` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` NOT NULL, `label` VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->compiler($dialect)->compileCreateTable($table),
        );
    }

    /**
     * Проверяет порядок опций у generated-колонки и сохранение FIRST/AFTER при ALTER.
     *
     * @see AbstractMySqlSchemaCompiler::compileAlterTableAddColumns()
     * @see AbstractMySqlSchemaCompiler::compileAlterTableChangeColumns()
     */
    #[Test]
    #[DataProvider('generatedCases')]
    public function generatedColumnKeepsCharacterOptionsAndPosition(string $dialect, string $operation, bool $stored): void
    {
        $table = new TableBlueprint('codes');

        $column = $table->string('copy', 64)->charset('utf8mb4')->collation('utf8mb4_bin')
                    ->nullable()->generatedAs('serial', $stored)->comment('Copy');
        $column->change($operation === 'change');
        if ($operation === 'add') {
            $column->first();
        } else {
            $column->after('serial');
        }
        $sql = $this->compile($this->compiler($dialect), $operation, $table);

        self::assertSame(
            'ALTER TABLE `codes` ' . ($operation === 'add' ? 'ADD' : 'MODIFY')
            . ' COLUMN `copy` VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` GENERATED ALWAYS AS (serial) '
            . ($stored ? 'STORED' : 'VIRTUAL') . " COMMENT 'Copy' " . ($operation === 'add' ? 'FIRST' : 'AFTER `serial`'),
            $sql,
        );
    }

    /**
     * Проверяет отказ для нетекстовых типов, включая раннюю ветку id и JSON MariaDB.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     */
    #[Test]
    #[DataProvider('unsupportedTypes')]
    public function rejectsOptionsOnNonCharacterTypes(string $dialect, string $operation, string $type): void
    {
        $table = new TableBlueprint('codes');

        $column       = $table->column('value');
        $column->type = $type;
        $column->collation('utf8mb4_bin')->change($operation === 'change');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('not supported for type');
        $this->compile($this->compiler($dialect), $operation, $table);
    }

    /**
     * Проверяет явный отказ PostgreSQL/SQLite, включая отдельную реализацию PostgreSQL change().
     *
     * @see PostgresSchemaCompiler::compileAlterTableChangeColumns()
     * @see SqliteSchemaCompiler::compileCreateTable()
     */
    #[Test]
    #[DataProvider('unsupportedDialects')]
    public function rejectsUnsupportedDialect(string $dialect, string $operation, string $option): void
    {
        $table = new TableBlueprint('codes');

        $column            = $table->string('serial')->change($operation === 'change');
        $column->{$option} = $option === 'charset' ? 'utf8mb4' : 'utf8mb4_bin';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('supported only by mysql/mariadb');
        $this->compile($this->compiler($dialect), $operation, $table);
    }

    /**
     * Проверяет отсутствие DDL, когда ошибка charset обнаружена после других действий blueprint.
     *
     * @see SchemaBuilder::alterTable()
     */
    #[Test]
    public function invalidOptionsFailBeforeAnyDdl(): void
    {
        $connection = new SpyConnection(new FakePdo('mysql'));

        $schema = new SchemaBuilder($connection, new MySqlSchemaCompiler());

        try {
            $schema->alterTable('codes', static function (TableBlueprint $table): void {
                $table->dropColumn('old_column');
                $table->string('valid_column');
                $table->string('serial')->change()->collation = 'utf8mb4_bin; DROP TABLE codes';
            });
            self::fail('Invalid collation must fail compilation.');
        } catch (ConfigurationException) {
            self::assertSame([], $connection->executed);
        }
    }

    /**
     * Проверяет неизменность определений id/AUTO_INCREMENT и временной колонки с ON UPDATE.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     */
    #[Test]
    #[DataProvider('mysqlDialects')]
    public function unrelatedAttributesStayUnchanged(string $dialect): void
    {
        $table = new TableBlueprint('codes');

        $table->id()->comment('Identifier');
        $table->datetime('updated_at')->useCurrent()->useCurrentOnUpdate();
        $table->string('serial', 64)->collation('utf8mb4_bin')->default('new');

        self::assertSame(
            "CREATE TABLE `codes` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT 'Identifier', `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `serial` VARCHAR(64) COLLATE `utf8mb4_bin` NOT NULL DEFAULT 'new')",
            $this->compiler($dialect)->compileCreateTable($table),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function mysqlDialects(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
    }

    /** @return iterable<string, array{string, string, string, ?string, ?string}> */
    public static function supportedCases(): iterable
    {
        foreach (['mysql', 'mariadb'] as $dialect) {
            foreach (['create', 'add', 'change'] as $operation) {
                foreach (['string', 'text'] as $type) {
                    foreach ([[null, null], ['utf8mb4', null], [null, 'utf8mb4_bin'], ['utf8mb4', 'utf8mb4_bin']] as $index => [$charset, $collation]) {
                        yield "$dialect/$operation/$type/$index" => [$dialect, $operation, $type, $charset, $collation];
                    }
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function generatedCases(): iterable
    {
        foreach (['mysql', 'mariadb'] as $dialect) {
            foreach (['add', 'change'] as $operation) {
                foreach ([true, false] as $stored) {
                    yield "$dialect/$operation/" . (int) $stored => [$dialect, $operation, $stored];
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsupportedTypes(): iterable
    {
        foreach (['mysql', 'mariadb'] as $dialect) {
            foreach (['create', 'add', 'change'] as $operation) {
                foreach (['id', 'integer', 'bigInteger', 'decimal', 'boolean', 'date', 'datetime', 'time', 'timestamp', 'json'] as $type) {
                    yield "$dialect/$operation/$type" => [$dialect, $operation, $type];
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unsupportedDialects(): iterable
    {
        foreach (['postgres', 'sqlite'] as $dialect) {
            foreach (['create', 'add', 'change'] as $operation) {
                foreach (['charset', 'collation'] as $option) {
                    yield "$dialect/$operation/$option" => [$dialect, $operation, $option];
                }
            }
        }
    }

    private function compiler(string $dialect): SchemaCompilerInterface
    {
        return match ($dialect) {
            'mysql'    => new MySqlSchemaCompiler(),
            'mariadb'  => new MariaDbSchemaCompiler(),
            'postgres' => new PostgresSchemaCompiler(),
            'sqlite'   => new SqliteSchemaCompiler(),
        };
    }

    private function compile(SchemaCompilerInterface $compiler, string $operation, TableBlueprint $table): string
    {
        return match ($operation) {
            'create' => $compiler->compileCreateTable($table),
            'add'    => implode('; ', $compiler->compileAlterTableAddColumns($table)),
            'change' => implode('; ', $compiler->compileAlterTableChangeColumns($table)),
        };
    }
}
