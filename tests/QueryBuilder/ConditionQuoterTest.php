<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use PhpSoftBox\Database\QueryBuilder\Compiler\ConditionQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\AnsiQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\MySqlQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConditionQuoter::class)]
#[CoversMethod(ConditionQuoter::class, 'quote')]
final class ConditionQuoterTest extends TestCase
{
    /**
     * Проверим, что слова внутри строкового литерала не берутся в кавычки.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsStringLiteralsUntouched(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"name" = \'hello world foo\'', $quoter->quote("name = 'hello world foo'"));
    }

    /**
     * Проверим, что CURRENT_TIMESTAMP остаётся ключевым словом, а не колонкой.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsCurrentTimestampKeyword(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"created_at" < CURRENT_TIMESTAMP', $quoter->quote('created_at < CURRENT_TIMESTAMP'));
    }

    /**
     * Проверим, что оператор ILIKE не берётся в кавычки.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsIlikeOperator(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"u"."name" ILIKE :q', $quoter->quote('u.name ILIKE :q'));
    }

    /**
     * Проверим, что имя функции не берётся в кавычки, а её аргумент-колонка экранируется.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsFunctionNames(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('lower("email") = :email', $quoter->quote('lower(email) = :email'));
    }

    /**
     * Проверим, что единица измерения после INTERVAL не берётся в кавычки.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsIntervalUnit(): void
    {
        $quoter = new ConditionQuoter(new MySqlQuoter());

        self::assertSame('`created_at` > NOW() - INTERVAL 1 DAY', $quoter->quote('created_at > NOW() - INTERVAL 1 DAY'));
    }

    /**
     * Проверим, что префикс типизированного литерала (DATE '...') не берётся в кавычки.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsTypedLiteralPrefix(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"day" >= DATE \'2024-01-01\'', $quoter->quote("day >= DATE '2024-01-01'"));
    }

    /**
     * Проверим, что приведение типа ::text не изменяется, а колонка перед ним экранируется.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsTypeCast(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"id"::text = :id', $quoter->quote('id::text = :id'));
    }

    /**
     * Проверим, что литерал MySQL с экранированной обратным слэшем кавычкой остаётся целым.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function keepsMySqlBackslashEscapedLiteral(): void
    {
        $quoter = new ConditionQuoter(new MySqlQuoter());

        self::assertSame('`note` = \'it\\\'s a test\' AND `a` = 1', $quoter->quote('note = \'it\\\'s a test\' AND a = 1'));
    }

    /**
     * Проверим, что колонки экранируются и без пробелов вокруг оператора.
     *
     * @see ConditionQuoter::quote()
     */
    #[Test]
    public function quotesColumnsWithoutSpacesAroundOperator(): void
    {
        $quoter = new ConditionQuoter(new AnsiQuoter());

        self::assertSame('"a"."id"="b"."user_id"', $quoter->quote('a.id=b.user_id'));
    }
}
