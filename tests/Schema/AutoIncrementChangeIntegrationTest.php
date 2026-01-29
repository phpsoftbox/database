<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractMySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_map;
use function bin2hex;
use function random_bytes;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(AbstractMySqlSchemaCompiler::class)]
#[CoversMethod(SchemaBuilder::class, 'alterTable')]
final class AutoIncrementChangeIntegrationTest extends TestCase
{
    /**
     * Изменение существующего id не дублирует первичный ключ, сохраняет строки и выдаёт следующий ID без ручного значения.
     *
     * @see SchemaBuilder::alterTable()
     * @see TableBlueprint::id()
     * @see ColumnBlueprint::autoIncrement()
     */
    #[Test]
    #[DataProvider('driversAndDeclarations')]
    public function changingIdPreservesKeyAndGeneratedValues(string $driver, bool $useId): void
    {
        try {
            $db = $driver === 'mysql'
                ? IntegrationDatabases::mysqlDatabase()
                : IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $exception) {
            self::markTestSkipped($exception->getMessage());
        }

        $builder   = new SchemaBuilderFactory()->create($db->connection());
        $tableName = 'id_change_' . bin2hex(random_bytes(6));
        $builder->create($tableName, static function (TableBlueprint $table): void {
            $table->comment('Auto increment migration regression');
            $table->id()->comment('Record identifier');
            $table->string('name')->comment('Record name');
        }, false);

        try {
            $db->execute("
                INSERT INTO `{$tableName}` (id, name)
                VALUES (7, 'first'), (41, 'second')
            ");

            $builder->alterTable($tableName, static function (TableBlueprint $table) use ($useId): void {
                $column = $useId ? $table->id() : $table->bigInteger('id')->unsigned()->autoIncrement();
                $column->comment('Updated record identifier')->change();
            });

            $column = $db->fetchAll("
                SELECT COLUMN_KEY, EXTRA, COLUMN_COMMENT
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tableName AND COLUMN_NAME = 'id'
            ", ['tableName' => $tableName])[0];
            self::assertSame('PRI', $column['COLUMN_KEY']);
            self::assertStringContainsString('auto_increment', $column['EXTRA']);
            self::assertSame('Updated record identifier', $column['COLUMN_COMMENT']);

            $db->execute("
                INSERT INTO `{$tableName}` (name) VALUES ('third')
            ");
            $rows = $db->fetchAll("
                SELECT id, name FROM `{$tableName}` ORDER BY id
            ");
            self::assertSame([7, 41, 42], array_map(static fn (array $row): int => (int) $row['id'], $rows));
            self::assertSame(['first', 'second', 'third'], array_map(static fn (array $row): string => $row['name'], $rows));
        } finally {
            $builder->drop($tableName);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function driversAndDeclarations(): iterable
    {
        yield 'MariaDB id change' => ['mariadb', true];
        yield 'MariaDB explicit auto increment' => ['mariadb', false];
        yield 'MySQL id change' => ['mysql', true];
        yield 'MySQL explicit auto increment' => ['mysql', false];
    }
}
