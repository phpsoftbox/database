<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TableBlueprint::class)]
final class TableBlueprintModifiersTest extends TestCase
{
    /**
     * Проверяет, что модификаторы таблицы (charset/collation/comment/engine/temporary)
     * корректно сохраняются в TableBlueprint.
     */
    #[Test]
    public function storesTableModifiers(): void
    {
        $t = new TableBlueprint('users');

        $t->charset('utf8mb4')
                    ->collation('utf8mb4_unicode_ci')
                    ->comment('Users table')
                    ->engine('InnoDB')
                    ->temporary();

        self::assertSame('utf8mb4', $t->charset);
        self::assertSame('utf8mb4_unicode_ci', $t->collation);
        self::assertSame('Users table', $t->comment);
        self::assertSame('InnoDB', $t->engine);
        self::assertTrue($t->temporary);
    }

    /**
     * Проверяет, что TableBlueprint::column() создаёт колонку по умолчанию с типом "text",
     * если колонки с таким именем ещё нет.
     */
    #[Test]
    public function columnCreatesDefaultTextColumn(): void
    {
        $t = new TableBlueprint('users');

        $c = $t->column('name');

        self::assertSame('name', $c->name);
        self::assertSame('text', $c->type);
    }

    /**
     * Проверяет, что хелперы типов datetime/timestamp на TableBlueprint создают колонки нужного типа.
     */
    #[Test]
    public function createsDatetimeAndTimestampColumns(): void
    {
        $t = new TableBlueprint('events');

        self::assertSame('datetime', $t->datetime('created_at')->type);
        self::assertSame('timestamp', $t->timestamp('updated_at')->type);
    }

    /**
     * Проверяет, что хелпер decimal на TableBlueprint сохраняет precision/scale.
     */
    #[Test]
    public function createsDecimalColumnWithPrecisionAndScale(): void
    {
        $t = new TableBlueprint('products');

        $c = $t->decimal('price', 12, 4);

        self::assertSame('decimal', $c->type);
        self::assertSame(12, $c->precision);
        self::assertSame(4, $c->scale);
    }

    /**
     * Проверяет, что dropColumn()/dropIndex()/dropForeignKey() сохраняют операции удаления в blueprint.
     */
    #[Test]
    public function storesDropOperations(): void
    {
        $t = new TableBlueprint('users');

        $t->dropColumn('legacy_email')
            ->dropColumn([
                'legacy_name',
                'legacy_phone',
            ])
            ->dropIndex('users_legacy_email_idx')
            ->dropIndex([
                'users_legacy_name_idx',
            ])
            ->dropForeignKey('users_legacy_user_fk')
            ->dropForeignKey([
                'users_legacy_group_fk',
            ]);

        self::assertSame([
            'legacy_email',
            'legacy_name',
            'legacy_phone',
        ], $t->droppedColumns());
        self::assertSame([
            'users_legacy_email_idx',
            'users_legacy_name_idx',
        ], $t->droppedIndexes());
        self::assertSame([
            'users_legacy_user_fk',
            'users_legacy_group_fk',
        ], $t->droppedForeignKeys());
    }
}
