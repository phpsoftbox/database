<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\UseCurrentFormatsEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ColumnBlueprint::class)]
#[CoversClass(UseCurrentFormatsEnum::class)]
final class ColumnBlueprintModifiersTest extends TestCase
{
    /**
     * Проверяет, что основные модификаторы (nullable/unsigned/default/comment/length) выставляются корректно.
     */
    #[Test]
    public function storesBasicModifiers(): void
    {
        $c = new ColumnBlueprint('email', 'string');

        $c->length = 255;
        $c->nullable()->unsigned()->default('x')->comment('Email');

        self::assertSame(255, $c->length);
        self::assertTrue($c->nullable);
        self::assertTrue($c->unsigned);
        self::assertSame('x', $c->default);
        self::assertSame('Email', $c->comment);
    }

    /**
     * Проверяет after()/first() для позиционирования колонок.
     */
    #[Test]
    public function storesAfterFirstModifiers(): void
    {
        $c = new ColumnBlueprint('b', 'integer');

        $c->after('a');

        self::assertFalse($c->isFirst);
        self::assertSame('a', $c->afterColumn);

        $c->first();
        self::assertTrue($c->isFirst);
        self::assertNull($c->afterColumn);
    }

    /**
     * Проверяет autoIncrement()/autoIncrementFrom().
     */
    #[Test]
    public function storesAutoIncrementModifiers(): void
    {
        $c = new ColumnBlueprint('id', 'integer');

        $c->autoIncrement(10);

        self::assertTrue($c->autoIncrement);
        self::assertSame(10, $c->autoIncrementFrom);

        $c->autoIncrementFrom(99);
        self::assertTrue($c->autoIncrement);
        self::assertSame(99, $c->autoIncrementFrom);
    }

    /**
     * Проверяет charset/collation.
     */
    #[Test]
    public function storesCharsetCollation(): void
    {
        $c = new ColumnBlueprint('title', 'string');

        $c->charset('utf8mb4')->collation('utf8mb4_unicode_ci');

        self::assertSame('utf8mb4', $c->charset);
        self::assertSame('utf8mb4_unicode_ci', $c->collation);
    }

    /**
     * Проверяет useCurrent/useCurrentOnUpdate с enum формата.
     */
    #[Test]
    public function storesUseCurrentFlagsAndFormat(): void
    {
        $column = new ColumnBlueprint('created_at', 'datetime');

        $column->useCurrent();
        self::assertTrue($column->useCurrent);
        self::assertSame(UseCurrentFormatsEnum::DATETIME, $column->useCurrentFormat);

        $column->useCurrentOnUpdate(true, UseCurrentFormatsEnum::TIMESTAMP);
        self::assertTrue($column->useCurrentOnUpdate);
        self::assertSame(UseCurrentFormatsEnum::TIMESTAMP, $column->useCurrentFormat);
    }

    /**
     * Проверяет sugar-методы datetime()/timestamp().
     */
    #[Test]
    public function changesTypeToDatetimeOrTimestamp(): void
    {
        $c = new ColumnBlueprint('dt', 'text');

        $c->datetime();
        self::assertSame('datetime', $c->type);

        $c->timestamp();
        self::assertSame('timestamp', $c->type);
    }
}
