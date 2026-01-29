<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\QueryBuilder\Quoting\AnsiQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\MySqlQuoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardQueryCompiler::class)]
#[CoversClass(AnsiQuoter::class)]
#[CoversClass(MySqlQuoter::class)]
final class QueryCompilerQuotingTest extends TestCase
{
    /**
     * Проверяет, что экранирование идентификаторов и алиасов работает правильно
     * и по-разному для ANSI (sqlite/pgsql) и MySQL/MariaDB.
     *
     * Важно: этот тест проверяет именно Quoter/Compiler, независимо от QueryBuilder.
     */
    #[DataProvider('quoterProvider')]
    #[Test]
    public function quotesIdentifiersAndAliasesCorrectly(string $dialect, StandardQueryCompiler $compiler, string $q): void
    {
        $quoter = $compiler->quoter();

        // ident()
        self::assertSame($q . 'users' . $q, $quoter->ident('users'), $dialect . ' ident');

        // dotted()
        self::assertSame(
            $q . 'users' . $q . '.' . $q . 'id' . $q,
            $quoter->dotted('users.id'),
            $dialect . ' dotted',
        );

        // tableWithOptionalAlias()
        self::assertSame(
            $q . 'users' . $q . ' AS ' . $q . 'u' . $q,
            $quoter->tableWithOptionalAlias('users u'),
            $dialect . ' table alias',
        );

        // alias() with reserved-ish token
        self::assertSame($q . 'order' . $q, $quoter->alias('order'), $dialect . ' alias');

        // alias with spaces
        self::assertSame($q . 'my alias' . $q, $quoter->alias('my alias'), $dialect . ' alias with spaces');

        // alias escaping quote chars
        $expectedEscaped = $dialect === 'mysql'
            ? '`a``b`'
            : '"a""b"';
        self::assertSame($expectedEscaped, $quoter->alias('a' . ($dialect === 'mysql' ? '`' : '"') . 'b'), $dialect . ' alias escaping');
    }

    /**
     * Проверяет, что SELECT-колонка с выражением и AS квотит только алиас.
     */
    #[DataProvider('quoterProvider')]
    #[Test]
    public function quotesSelectExpressionAliasOnly(string $dialect, StandardQueryCompiler $compiler, string $q): void
    {
        // EXISTS(...) AS alias
        $quoted = $compiler->quoteSelectColumn('EXISTS (SELECT 1) AS has_paid');
        self::assertSame('EXISTS (SELECT 1) AS ' . $q . 'has_paid' . $q, $quoted, $dialect);

        // COUNT(*) AS cnt
        $quoted2 = $compiler->quoteSelectColumn('COUNT(*) AS cnt');
        self::assertSame('COUNT(*) AS ' . $q . 'cnt' . $q, $quoted2, $dialect);
    }

    /**
     * @return iterable<array{0: string, 1: StandardQueryCompiler, 2: string}>
     */
    public static function quoterProvider(): iterable
    {
        yield 'ansi' => ['ansi', new StandardQueryCompiler(new AnsiQuoter()), '"'];
        yield 'mysql' => ['mysql', new StandardQueryCompiler(new MySqlQuoter()), '`'];
    }
}
