<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Driver\MySqlDriver;
use PhpSoftBox\Database\SchemaBuilder\Compiler\MySqlSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\ExistingTablesSpyConnection;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_column;
use function array_filter;
use function str_starts_with;

#[CoversClass(SchemaBuilder::class)]
#[CoversMethod(SchemaBuilder::class, 'createIfNotExists')]
final class CreateIfNotExistsWithoutIndexIfNotExistsTest extends TestCase
{
    /**
     * Проверим, что на MySQL для существующей таблицы createIfNotExists() не выполняет CREATE TABLE/INDEX.
     *
     * @see SchemaBuilder::createIfNotExists()
     */
    #[Test]
    public function skipsExistingTableOnMySql(): void
    {
        $connection = new ExistingTablesSpyConnection(new FakePdo('mysql'), driver: new MySqlDriver());

        new SchemaBuilder($connection, new MySqlSchemaCompiler())->createIfNotExists('users', static function (TableBlueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });

        self::assertStringContainsString('information_schema.tables', $connection->executed[0]['sql']);
        self::assertSame([], array_filter(
            array_column($connection->executed, 'sql'),
            static fn (string $sql): bool => str_starts_with($sql, 'CREATE'),
        ));
    }

    /**
     * Проверим на MariaDB с MySQL-компилятором (CREATE INDEX без IF NOT EXISTS), что повторный
     * createIfNotExists() не падает с ошибкой 1061 (Duplicate key name).
     *
     * @see SchemaBuilder::createIfNotExists()
     */
    #[Test]
    public function repeatedCreateIfNotExistsSucceedsOnMariaDb(): void
    {
        try {
            $connection = IntegrationDatabases::mariadbDatabase()->connection();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $builder    = new SchemaBuilder($connection, new MySqlSchemaCompiler());
        $definition = static function (TableBlueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        };

        $connection->execute('DROP TABLE IF EXISTS psb_create_twice');

        try {
            $builder->createIfNotExists('psb_create_twice', $definition);
            $builder->createIfNotExists('psb_create_twice', $definition);

            self::assertNotNull($connection->fetchOne('SELECT 1 AS ok FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i', [
                't' => 'psb_create_twice',
                'i' => 'psb_create_twice_email_unique',
            ]));
        } finally {
            $connection->execute('DROP TABLE IF EXISTS psb_create_twice');
        }
    }
}
