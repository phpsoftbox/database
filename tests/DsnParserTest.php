<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Dsn\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DsnParser::class)]
final class DsnParserTest extends TestCase
{
    /**
     * Проверяет парсинг sqlite:///:memory:.
     */
    #[Test]
    public function parsesSqliteMemory(): void
    {
        $dsn = new DsnParser()->parse('sqlite:///:memory:');

        self::assertSame('sqlite', $dsn->driver);
        self::assertSame(':memory:', $dsn->path);
    }

    /**
     * Проверяет парсинг sqlite:////abs/path.
     */
    #[Test]
    public function parsesSqliteAbsolutePath(): void
    {
        $dsn = new DsnParser()->parse('sqlite:////tmp/test.sqlite');

        self::assertSame('sqlite', $dsn->driver);
        self::assertSame('/tmp/test.sqlite', $dsn->path);
    }

    /**
     * Проверяет парсинг URL DSN для сетевых драйверов.
     */
    #[Test]
    public function parsesNetworkDsn(): void
    {
        $dsn = new DsnParser()->parse('postgres://user:pass@localhost:5432/app?sslmode=disable');

        self::assertSame('postgres', $dsn->driver);
        self::assertSame('localhost', $dsn->host);
        self::assertSame(5432, $dsn->port);
        self::assertSame('app', $dsn->database);
        self::assertSame('user', $dsn->user);
        self::assertSame('pass', $dsn->password);
        self::assertSame(['sslmode' => 'disable'], $dsn->params);
    }

    /**
     * Проверим, что URL-кодированные логин, пароль и имя БД декодируются.
     *
     * @see DsnParser::parse()
     */
    #[Test]
    public function decodesUrlEncodedCredentials(): void
    {
        $dsn = new DsnParser()->parse('postgres://app%20user:p%40ss%3Aw%2Frd@localhost:5432/my%2Ddb');

        self::assertSame('app user', $dsn->user);
        self::assertSame('p@ss:w/rd', $dsn->password);
        self::assertSame('my-db', $dsn->database);
    }

    #[Test]
    public function keepsMySqlAndMariaDbAsDistinctDrivers(): void
    {
        $mysql   = new DsnParser()->parse('mysql://user:pass@localhost/app');
        $mariaDb = new DsnParser()->parse('mariadb://user:pass@localhost/app');

        self::assertSame('mysql', $mysql->driver);
        self::assertSame('mariadb', $mariaDb->driver);
    }
}
