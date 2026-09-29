<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardQueryCompiler::class)]
#[CoversMethod(StandardQueryCompiler::class, 'compileSelect')]
#[CoversMethod(StandardQueryCompiler::class, 'compileInsert')]
final class DialectSpecificSqlTest extends TestCase
{
    /**
     * Проверим, что на MySQL/MariaDB части UNION встраиваются в скобках, а SQLite-обёртка не используется.
     *
     * @see StandardQueryCompiler::compileSelect()
     */
    #[Test]
    public function unionPartsAreParenthesizedOnMariaDb(): void
    {
        $conn = new SpyConnection(new FakePdo('mysql'), driver: new MariaDbDriver());

        $built = $conn->query()
            ->select('id')
            ->from('users')
            ->union($conn->query()->select('id')->from('admins'))
            ->toSql();

        self::assertSame('SELECT `id` FROM `users` UNION (SELECT `id` FROM `admins`)', $built['sql']);
    }

    /**
     * Проверим, что INSERT без колонок на MySQL/MariaDB компилируется в `() VALUES ()`.
     *
     * @see StandardQueryCompiler::compileInsert()
     */
    #[Test]
    public function emptyInsertUsesEmptyValuesListOnMariaDb(): void
    {
        $conn = new SpyConnection(new FakePdo('mysql'), driver: new MariaDbDriver());

        $built = $conn->query()->insert('users')->toSql();

        self::assertSame('INSERT INTO `users` () VALUES ()', $built['sql']);
    }

    /**
     * Проверим, что INSERT без колонок на PostgreSQL компилируется в DEFAULT VALUES.
     *
     * @see StandardQueryCompiler::compileInsert()
     */
    #[Test]
    public function emptyInsertUsesDefaultValuesOnPostgres(): void
    {
        $conn = new SpyConnection(new FakePdo('pgsql'), driver: new PostgresDriver());

        $built = $conn->query()->insert('users')->toSql();

        self::assertSame('INSERT INTO "users" DEFAULT VALUES', $built['sql']);
    }
}
