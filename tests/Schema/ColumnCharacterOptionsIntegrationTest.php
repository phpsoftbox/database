<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PDOException;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_column;
use function bin2hex;
use function random_bytes;
use function strtolower;
use function strtoupper;

#[CoversClass(SchemaBuilderFactory::class)]
#[CoversMethod(SchemaBuilderFactory::class, 'create')]
final class ColumnCharacterOptionsIntegrationTest extends TestCase
{
    private ?Database $database = null;
    private string $tableName   = '';

    protected function tearDown(): void
    {
        if ($this->database !== null && $this->tableName !== '') {
            $this->database->execute("
                DROP TABLE IF EXISTS `{$this->tableName}`
            ");
        }

        parent::tearDown();
    }

    /**
     * Проверяет реальные метаданные, default, generated и соседнюю колонку после CREATE/ADD/MODIFY.
     *
     * @see SchemaBuilderInterface::create()
     * @see SchemaBuilderInterface::alterTable()
     */
    #[Test]
    #[DataProvider('operationCases')]
    public function storesColumnMetadata(string $dialect, string $operation): void
    {
        [$db, $schema] = $this->prepare($dialect, $operation);
        $columns       = $this->columnMetadata($db);

        foreach (['serial_number', 'raw_value', 'serial_copy'] as $name) {
            self::assertSame('utf8mb4', $columns[$name]['CHARACTER_SET_NAME']);
            self::assertSame('utf8mb4_bin', $columns[$name]['COLLATION_NAME']);
        }
        self::assertSame('varchar', $columns['serial_number']['DATA_TYPE']);
        self::assertSame(64, (int) $columns['serial_number']['CHARACTER_MAXIMUM_LENGTH']);
        self::assertSame('NO', $columns['serial_number']['IS_NULLABLE']);
        self::assertSame("Seller's serial", $columns['serial_number']['COLUMN_COMMENT']);
        self::assertSame('text', $columns['raw_value']['DATA_TYPE']);
        self::assertSame('YES', $columns['raw_value']['IS_NULLABLE']);
        self::assertSame('Raw code', $columns['raw_value']['COLUMN_COMMENT']);
        self::assertSame('utf8mb4_unicode_ci', $columns['label']['COLLATION_NAME']);
        self::assertStringContainsString('auto_increment', strtolower($columns['id']['EXTRA']));
        self::assertStringContainsString('on update current_timestamp', strtolower($columns['updated_at']['EXTRA']));

        $db->execute("
            INSERT INTO `{$this->tableName}` (group_code, label) VALUES (:group_code, :label)
        ", ['group_code' => 'default-test', 'label' => 'Label']);
        $row = $db->fetchOne("
            SELECT serial_number, serial_copy, raw_value, updated_at
            FROM `{$this->tableName}` WHERE group_code = :group_code
        ", ['group_code' => 'default-test']);
        self::assertSame('default-serial', $row['serial_number']);
        self::assertSame('default-serial', $row['serial_copy']);
        self::assertNull($row['raw_value']);
        self::assertNotNull($row['updated_at']);

        // Повторное полное определение сохраняет результат и данные.
        $schema->alterTable($this->tableName, static fn (TableBlueprint $table) => self::defineCharacterColumns($table, true));
        self::assertSame($columns, $this->columnMetadata($db));
    }

    /**
     * Проверяет регистрозависимый составной unique: разные регистры допустимы, точный дубль — нет.
     *
     * @see SchemaBuilderInterface::alterTable()
     * @see TableBlueprint::unique()
     */
    #[Test]
    #[DataProvider('operationCases')]
    public function uniqueIndexDistinguishesCase(string $dialect, string $operation): void
    {
        [$db] = $this->prepare($dialect, $operation);
        foreach (['AbC', 'abc'] as $serial) {
            $db->execute("
                INSERT INTO `{$this->tableName}` (group_code, serial_number, label)
                VALUES (:group_code, :serial, :label)
            ", ['group_code' => 'group-1', 'serial' => $serial, 'label' => 'Label']);
        }
        $rows = $db->fetchAll("
            SELECT serial_number FROM `{$this->tableName}`
            WHERE group_code = :group_code ORDER BY serial_number
        ", ['group_code' => 'group-1']);
        self::assertCount(2, $rows);

        try {
            $db->execute("
                INSERT INTO `{$this->tableName}` (group_code, serial_number, label)
                VALUES (:group_code, :serial, :label)
            ", ['group_code' => 'group-1', 'serial' => 'AbC', 'label' => 'Label']);
            self::fail('An exact duplicate must violate the unique index.');
        } catch (QueryException $e) {
            self::assertInstanceOf(PDOException::class, $e->getPrevious());
            self::assertSame(1062, $e->getPrevious()->errorInfo[1]);
        }
    }

    /**
     * Проверяет сохранение исходных байтов, включая регистр, пробелы и разделитель, при смене collation.
     *
     * @see SchemaBuilderInterface::alterTable()
     */
    #[Test]
    #[DataProvider('dialects')]
    public function changePreservesExistingBytes(string $dialect): void
    {
        [$db, $schema] = $this->connect($dialect);
        $this->createBase($schema, true, false);
        $raw    = "Code-Аa\x1Dtail\\+== ";
        $serial = 'AbC ';
        $db->execute("
            INSERT INTO `{$this->tableName}` (group_code, serial_number, raw_value, label)
            VALUES (:group_code, :serial, :raw, :label)
        ", ['group_code' => 'group-1', 'serial' => $serial, 'raw' => $raw, 'label' => 'Untouched']);
        $before = $db->fetchAll("
            SELECT id, HEX(serial_number) AS serial_hex, HEX(raw_value) AS raw_hex, label
            FROM `{$this->tableName}` ORDER BY id
        ");

        $schema->alterTable($this->tableName, static fn (TableBlueprint $table) => self::defineCharacterColumns($table, true));

        self::assertSame($before, $db->fetchAll("
            SELECT id, HEX(serial_number) AS serial_hex, HEX(raw_value) AS raw_hex, label
            FROM `{$this->tableName}` ORDER BY id
        "));
        self::assertSame(strtoupper(bin2hex($serial)), $before[0]['serial_hex']);
        self::assertSame(strtoupper(bin2hex($raw)), $before[0]['raw_hex']);
    }

    /**
     * Проверяет серверную семантику отдельно заданного charset/collation во всех трёх операциях.
     *
     * @see SchemaBuilderInterface::create()
     * @see SchemaBuilderInterface::alterTable()
     */
    #[Test]
    #[DataProvider('singleOptionCases')]
    public function supportsSingleOption(string $dialect, string $operation, string $option): void
    {
        [$db, $schema] = $this->connect($dialect);
        $define        = static function (TableBlueprint $table) use ($option, $operation): void {
            $column = $table->string('value', 40)->nullable()->comment('Value');
            $column->{$option}($option === 'charset' ? 'utf8mb4' : 'utf8mb4_bin');
            $column->change($operation === 'change');
        };
        $schema->create($this->tableName, static function (TableBlueprint $table) use ($define, $operation): void {
            $table->charset('latin1')->collation('latin1_bin');
            $table->id()->comment('Identifier');
            if ($operation === 'create') {
                $define($table);
            } elseif ($operation === 'change') {
                $table->string('value', 40)->nullable()->comment('Value');
            }
        }, false);
        if ($operation !== 'create') {
            $schema->alterTable($this->tableName, $define);
        }
        $metadata = $this->columnMetadata($db)['value'];
        self::assertSame('utf8mb4', $metadata['CHARACTER_SET_NAME']);
        if ($option === 'collation') {
            self::assertSame('utf8mb4_bin', $metadata['COLLATION_NAME']);
        } else {
            // Default collation зависит от версии и конфигурации СУБД: не зашиваем её имя.
            $default = $db->fetchOne("
                SELECT DEFAULT_COLLATE_NAME FROM information_schema.CHARACTER_SETS
                WHERE CHARACTER_SET_NAME = 'utf8mb4'
            ");
            self::assertSame($default['DEFAULT_COLLATE_NAME'], $metadata['COLLATION_NAME']);
        }
    }

    /**
     * Проверяет, что несовместимость пары отклоняется СУБД и не исправляется клиентом молча.
     *
     * @see SchemaBuilderInterface::create()
     */
    #[Test]
    #[DataProvider('dialects')]
    public function databaseRejectsIncompatiblePair(string $dialect): void
    {
        [, $schema] = $this->connect($dialect);
        $this->expectException(QueryException::class);
        $schema->create($this->tableName, static function (TableBlueprint $table): void {
            $table->string('value')->charset('latin1')->collation('utf8mb4_bin')->comment('Value');
        }, false);
    }

    /** @return iterable<string, array{string}> */
    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mariadb' => ['mariadb'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function operationCases(): iterable
    {
        foreach (['mysql', 'mariadb'] as $dialect) {
            foreach (['create', 'add', 'change'] as $operation) {
                yield "$dialect/$operation" => [$dialect, $operation];
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function singleOptionCases(): iterable
    {
        foreach (self::operationCases() as $key => [$dialect, $operation]) {
            foreach (['charset', 'collation'] as $option) {
                yield "$key/$option" => [$dialect, $operation, $option];
            }
        }
    }

    /** @return array{Database, SchemaBuilderInterface} */
    private function connect(string $dialect): array
    {
        try {
            $db = $dialect === 'mysql' ? IntegrationDatabases::mysqlDatabase() : IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }
        $this->database  = $db;
        $this->tableName = 'column_options_' . bin2hex(random_bytes(6));

        return [$db, new SchemaBuilderFactory()->create($db->connection())];
    }

    /** @return array{Database, SchemaBuilderInterface} */
    private function prepare(string $dialect, string $operation): array
    {
        [$db, $schema] = $this->connect($dialect);
        $this->createBase($schema, $operation !== 'add', $operation === 'create');
        if ($operation !== 'create') {
            $schema->alterTable($this->tableName, static function (TableBlueprint $table) use ($operation): void {
                self::defineCharacterColumns($table, $operation === 'change');
                if ($operation === 'add') {
                    $table->unique(['group_code', 'serial_number'], 'group_serial_unique');
                }
            });
        }

        return [$db, $schema];
    }

    private function createBase(SchemaBuilderInterface $schema, bool $withColumns, bool $binary): void
    {
        $schema->create($this->tableName, static function (TableBlueprint $table) use ($withColumns, $binary): void {
            $table->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->comment('Character option tests');
            $table->id()->comment('Identifier');
            $table->string('group_code', 32)->comment('Group');
            $table->string('label', 80)->comment('Unchanged column');
            $table->datetime('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('Updated at');
            if ($withColumns) {
                self::defineCharacterColumns($table, false, $binary);
                $table->unique(['group_code', 'serial_number'], 'group_serial_unique');
            }
        }, false);
    }

    private static function defineCharacterColumns(TableBlueprint $table, bool $change, bool $binary = true): void
    {
        $columns = [
            $table->string('serial_number', 64)->default('default-serial')->comment("Seller's serial"),
            $table->text('raw_value')->nullable()->comment('Raw code'),
            $table->string('serial_copy', 64)->nullable()->generatedAs('serial_number', false)->comment('Generated serial'),
        ];
        foreach ($columns as $column) {
            $column->change($change);
            if ($binary) {
                $column->charset('utf8mb4')->collation('utf8mb4_bin');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function columnMetadata(Database $db): array
    {
        $rows = $db->fetchAll("
            -- Include the column's inherited settings.
            SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_SET_NAME,
                COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
            ORDER BY ORDINAL_POSITION
        ", ['table_name' => $this->tableName]);

        return array_column($rows, null, 'COLUMN_NAME');
    }
}
