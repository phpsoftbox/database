<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversNothing]
final class GeneratedColumnsMySqlIntegrationTest extends TestCase
{
    #[Test]
    public function mysqlUsesItsOwnGeneratedColumnAndIndexDialect(): void
    {
        try {
            $db = IntegrationDatabases::mysqlDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::assertSame('mysql', $db->connection()->driver()->name());

        $builder = new SchemaBuilderFactory()->create($db->connection());

        try {
            $db->execute('DROP TABLE IF EXISTS generated_order_lines');

            $builder->create('generated_order_lines', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('price');
                $table->integer('quantity');
                $table->integer('total')
                    ->generatedAs('price * quantity')
                    ->unique('generated_order_lines_total_unique');
            }, false);

            $db->execute(
                'INSERT INTO generated_order_lines (price, quantity) VALUES (:price, :quantity)',
                ['price' => 5, 'quantity' => 4],
            );

            $row = $db->fetchOne('SELECT total FROM generated_order_lines WHERE id = 1');

            self::assertSame(20, (int) $row['total']);
            self::assertTrue($db->schema()->hasIndex(
                'generated_order_lines',
                'generated_order_lines_total_unique',
            ));
        } finally {
            $db->execute('DROP TABLE IF EXISTS generated_order_lines');
        }
    }
}
