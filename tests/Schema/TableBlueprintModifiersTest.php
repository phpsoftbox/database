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
}
