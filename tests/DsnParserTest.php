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
}
