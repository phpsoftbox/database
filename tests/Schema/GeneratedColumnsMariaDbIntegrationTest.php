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
final class GeneratedColumnsMariaDbIntegrationTest extends TestCase
{
    #[Test]
    public function generatedColumnIsCalculatedAndEnforcesUniqueIndex(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $builder = new SchemaBuilderFactory()->create($db->connection());

        try {
            $db->execute('DROP TABLE IF EXISTS generated_memberships');

            $builder->create('generated_memberships', function (TableBlueprint $table): void {
                $table->id();
                $table->integer('price');
                $table->integer('quantity');
                $table->datetime('deleted_datetime')->nullable();
                $table->integer('total')->generatedAs('price * quantity');
            }, false);

            $builder->alterTable('generated_memberships', function (TableBlueprint $table): void {
                $table->boolean('active_marker')
                    ->nullable()
                    ->generatedAs('IF(deleted_datetime IS NULL, 1, NULL)')
                    ->unique('generated_memberships_active_unique');
            });

            $db->execute(
                'INSERT INTO generated_memberships (price, quantity, deleted_datetime) '
                . 'VALUES (:price, :quantity, :deleted_datetime)',
                ['price' => 5, 'quantity' => 4, 'deleted_datetime' => null],
            );
            $db->execute(
                'INSERT INTO generated_memberships (price, quantity, deleted_datetime) '
                . 'VALUES (:price, :quantity, :deleted_datetime)',
                ['price' => 2, 'quantity' => 3, 'deleted_datetime' => '2026-01-01 00:00:00'],
            );
            $db->execute(
                'INSERT INTO generated_memberships (price, quantity, deleted_datetime) '
                . 'VALUES (:price, :quantity, :deleted_datetime)',
                ['price' => 1, 'quantity' => 7, 'deleted_datetime' => '2026-01-02 00:00:00'],
            );

            $rows = $db->fetchAll('SELECT total, active_marker FROM generated_memberships ORDER BY id');
            self::assertSame(20, (int) $rows[0]['total']);
            self::assertSame(1, (int) $rows[0]['active_marker']);
            self::assertNull($rows[1]['active_marker']);
            self::assertNull($rows[2]['active_marker']);

            $builder->alterTable('generated_memberships', function (TableBlueprint $table): void {
                $table->integer('total')->generatedAs('price * quantity * 2')->change();
            });

            $changed = $db->fetchOne('SELECT total FROM generated_memberships WHERE id = 1');
            self::assertSame(40, (int) $changed['total']);

            $thrown = false;
            try {
                $db->execute(
                    'INSERT INTO generated_memberships (price, quantity, deleted_datetime) '
                    . 'VALUES (:price, :quantity, :deleted_datetime)',
                    ['price' => 1, 'quantity' => 1, 'deleted_datetime' => null],
                );
            } catch (Throwable) {
                $thrown = true;
            }

            self::assertTrue($thrown, 'Unique index must reject a second active generated marker.');
        } finally {
            $db->execute('DROP TABLE IF EXISTS generated_memberships');
        }
    }
}
