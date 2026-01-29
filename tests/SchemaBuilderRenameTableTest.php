<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\SchemaBuilder\Compiler\AbstractSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SqliteSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilder;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaBuilder::class)]
#[CoversClass(AbstractSchemaCompiler::class)]
#[CoversClass(SqliteSchemaCompiler::class)]
final class SchemaBuilderRenameTableTest extends TestCase
{
    /**
     * Проверяет, что schema builder умеет переименовывать таблицу.
     */
    #[Test]
    public function renamesTableInSqlite(): void
    {
        $db = Database::fromConfig([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn' => 'sqlite:///:memory:',
                ],
            ],
        ]);

        $schema  = $db->schema();
        $builder = new SchemaBuilderFactory()->create($db->connection());

        $builder->create('users', function (TableBlueprint $table): void {
            $table->id();
            $table->text('name');
        });

        self::assertTrue($schema->hasTable('users'));

        $builder->renameTable('users', 'people');

        $schema2 = $db->schema();
        self::assertFalse($schema2->hasTable('users'));
        self::assertTrue($schema2->hasTable('people'));
    }
}
