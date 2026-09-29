<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use InvalidArgumentException;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\QueryBuilder\Quoting\AbstractQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\AnsiQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\MySqlQuoter;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\UpdateQueryBuilder;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractQuoter::class)]
#[CoversClass(AnsiQuoter::class)]
#[CoversClass(MySqlQuoter::class)]
#[CoversMethod(AbstractQuoter::class, 'ident')]
#[CoversMethod(AbstractQuoter::class, 'dotted')]
#[CoversMethod(AbstractQuoter::class, 'alias')]
#[CoversMethod(AbstractQuoter::class, 'tableWithOptionalAlias')]
final class IdentifierQuotingTest extends TestCase
{
    /**
     * Проверим, что ключ данных UPDATE в «уже экранированной» форме с SQL-фрагментом отклоняется,
     * а не встраивается в SET (инъекция через mass-assignment).
     *
     * @see UpdateQueryBuilder::toSql()
     * @see AbstractQuoter::ident()
     */
    #[Test]
    public function updateRejectsInjectedColumnKey(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->update('users', ['"is_admin" = true, "name"' => 'x'])->where('id = :id', ['id' => 1])->toSql();
    }

    /**
     * Проверим, что ключ данных INSERT с чужими кавычками и SQL-фрагментом отклоняется на MySQL/MariaDB.
     *
     * @see AbstractQuoter::ident()
     */
    #[Test]
    public function insertRejectsInjectedColumnKeyOnMariaDb(): void
    {
        $conn = new SpyConnection(new FakePdo('mysql'), driver: new MariaDbDriver());

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->insert('users', ['`is_admin`) VALUES (1) -- `' => 'x'])->toSql();
    }

    /**
     * Проверим, что ключ массивного where() с SQL-фрагментом отклоняется (фильтры из данных запроса).
     *
     * @see SelectQueryBuilder::where()
     */
    #[Test]
    public function whereArrayRejectsInjectedColumnKey(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->select()->from('users')->where(['id = 1 OR 1' => 5]);
    }

    /**
     * Проверим, что неизвестный оператор массивного where() отклоняется.
     *
     * @see SelectQueryBuilder::where()
     */
    #[Test]
    public function whereArrayRejectsUnknownOperator(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->select()->from('users')->where([['id', '= 1 OR 1 =', 5]]);
    }

    /**
     * Проверим, что сортировка по строке с SQL-фрагментом отклоняется.
     *
     * @see AbstractQuoter::dotted()
     */
    #[Test]
    public function orderByRejectsInjectedColumn(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->select()->from('users')->orderBy('id; DROP TABLE users')->toSql();
    }

    /**
     * Проверим, что простое имя экранируется, а корректно экранированное имя текущего диалекта остаётся как есть.
     *
     * @see AbstractQuoter::ident()
     */
    #[Test]
    public function identAcceptsBareAndProperlyQuotedNames(): void
    {
        $quoter = new AnsiQuoter();

        self::assertSame('"created_at"', $quoter->ident('created_at'));
        self::assertSame('"my ""name"""', $quoter->ident('"my ""name"""'));
    }

    /**
     * Проверим, что имя в кавычках другого диалекта (backtick для ANSI) отклоняется.
     *
     * @see AbstractQuoter::ident()
     */
    #[Test]
    public function identRejectsForeignQuotes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AnsiQuoter()->ident('`users`');
    }

    /**
     * Проверим, что имя в кавычках с некорректно экранированной внутренней кавычкой отклоняется.
     *
     * @see AbstractQuoter::ident()
     */
    #[Test]
    public function identRejectsBrokenQuotedName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MySqlQuoter()->ident('`a` = 1 OR `b`');
    }

    /**
     * Проверим, что dotted() поддерживает schema.table.column, сегменты в кавычках и завершающую звёздочку.
     *
     * @see AbstractQuoter::dotted()
     */
    #[Test]
    public function dottedSupportsSchemaQuotedSegmentsAndStar(): void
    {
        $quoter = new AnsiQuoter();

        self::assertSame('"public"."users"."id"', $quoter->dotted('public.users.id'));
        self::assertSame('"my.schema"."users"', $quoter->dotted('"my.schema".users'));
        self::assertSame('"u".*', $quoter->dotted('u.*'));
    }

    /**
     * Проверим, что алиас с пробелами и кавычками экранируется, а не отклоняется.
     *
     * @see AbstractQuoter::alias()
     */
    #[Test]
    public function aliasEscapesArbitraryText(): void
    {
        self::assertSame('"a"" b"', new AnsiQuoter()->alias('a" b'));
    }

    /**
     * Проверим, что имя таблицы принимает алиас с AS и без него.
     *
     * @see AbstractQuoter::tableWithOptionalAlias()
     */
    #[Test]
    public function tableWithOptionalAliasSupportsAsKeyword(): void
    {
        $quoter = new MySqlQuoter();

        self::assertSame('`users` AS `u`', $quoter->tableWithOptionalAlias('users u'));
        self::assertSame('`users` AS `u`', $quoter->tableWithOptionalAlias('users AS u'));
    }

    /**
     * Проверим, что имя таблицы с лишними словами отклоняется.
     *
     * @see AbstractQuoter::tableWithOptionalAlias()
     */
    #[Test]
    public function tableWithOptionalAliasRejectsExtraTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AnsiQuoter()->tableWithOptionalAlias('users u WHERE 1 = 1');
    }
}
