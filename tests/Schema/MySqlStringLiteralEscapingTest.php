<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractMySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(AbstractMySqlSchemaCompiler::class)]
#[CoversClass(MariaDbSchemaCompiler::class)]
#[CoversMethod(AbstractMySqlSchemaCompiler::class, 'compileCreateTable')]
final class MySqlStringLiteralEscapingTest extends TestCase
{
    /**
     * Проверим, что обратный слэш и кавычка в строковом default экранируются для MySQL/MariaDB.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     */
    #[Test]
    public function escapesBackslashInStringDefault(): void
    {
        $table = new TableBlueprint('paths');

        $table->string('path')->default('C:\\temp\\it\'s');

        self::assertStringContainsString(
            "`path` VARCHAR(255) NOT NULL DEFAULT 'C:\\\\temp\\\\it''s'",
            new MariaDbSchemaCompiler()->compileCreateTable($table),
        );
    }

    /**
     * Проверим, что в MariaDB default с обратным слэшем в конце сохраняется без изменений.
     *
     * @see AbstractMySqlSchemaCompiler::compileCreateTable()
     */
    #[Test]
    public function storesBackslashDefaultInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $conn = $db->connection();
        $conn->schema()->dropIfExists('psb_backslash_default');

        try {
            $conn->schema()->create('psb_backslash_default', static function (TableBlueprint $table): void {
                $table->id();
                $table->string('path')->default('C:\\temp\\')->comment('Путь \\ с обратным слэшем');
            });

            $conn->execute('INSERT INTO psb_backslash_default (id) VALUES (1)');

            self::assertSame('C:\\temp\\', $conn->fetchOne('SELECT path FROM psb_backslash_default')['path'] ?? null);
        } finally {
            $conn->schema()->dropIfExists('psb_backslash_default');
        }
    }
}
