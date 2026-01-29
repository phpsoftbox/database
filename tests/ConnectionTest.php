<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\IsolationLevelEnum;
use PhpSoftBox\Database\Tests\Utils\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_key_last;

#[CoversClass(DatabaseFactory::class)]
#[CoversClass(Connection::class)]
#[CoversClass(IsolationLevelEnum::class)]
final class ConnectionTest extends TestCase
{
    /**
     * Проверяет, что можно выполнить запросы на sqlite::memory:, а префикс таблиц применяется через table().
     */
    #[Test]
    public function executesQueriesAndUsesPrefix(): void
    {
        $logger = new SpyLogger();

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn'    => 'sqlite:///:memory:',
                    'prefix' => 't_',
                ],
            ],
        ], $logger);

        $conn = $factory->create('default');

        $conn->execute('CREATE TABLE ' . $conn->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);

        $rows = $conn->fetchAll('SELECT id, name FROM ' . $conn->table('users'));

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['name']);

        // Проверяем, что логгер реально отрабатывает
        self::assertNotEmpty($logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertSame('DB query executed', $logger->records[0]['message']);
        self::assertArrayHasKey('sql', $logger->records[0]['context']);
    }

    /**
     * Проверяет, что transaction() делает rollback при исключении.
     */
    #[Test]
    public function rollsBackTransactionOnException(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn' => 'sqlite:///:memory:',
                ],
            ],
        ]);

        $conn = $factory->create();
        $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        try {
            $conn->transaction(function ($c): void {
                $c->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

                throw new RuntimeException('boom');
            });
            self::fail('Exception was expected.');
        } catch (RuntimeException) {
            // ok
        }

        $rows = $conn->fetchAll('SELECT name FROM users');
        self::assertSame([], $rows);
    }

    /**
     * Проверяет, что для sqlite выставляется PRAGMA read_uncommitted.
     */
    #[Test]
    public function appliesIsolationLevelInSqlite(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $conn = new Connection($pdo, new SqliteDriver());

        $conn->transaction(static function (): void {
            // no-op
        }, IsolationLevelEnum::READ_UNCOMMITTED);

        $value = (int) $pdo->query('PRAGMA read_uncommitted')->fetchColumn();
        self::assertSame(1, $value);

        $conn->transaction(static function (): void {
            // no-op
        }, IsolationLevelEnum::READ_COMMITTED);

        $value = (int) $pdo->query('PRAGMA read_uncommitted')->fetchColumn();
        self::assertSame(0, $value);
    }

    /**
     * Проверяет, что для non-sqlite драйвера выставляется SET TRANSACTION ISOLATION LEVEL.
     */
    #[Test]
    public function appliesIsolationLevelForNonSqliteDriver(): void
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $pdo->expects(self::once())
            ->method('exec')
            ->with('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
            ->willReturn(0);

        $pdo->expects(self::once())
            ->method('commit')
            ->willReturn(true);

        $pdo->method('inTransaction')->willReturn(true);

        $conn = new Connection($pdo, new PostgresDriver());

        $conn->transaction(static function (): void {
            // no-op
        }, IsolationLevelEnum::REPEATABLE_READ);
    }

    /**
     * Проверяет, что вложенные транзакции используют savepoint.
     */
    #[Test]
    public function usesSavepointsForNestedTransactions(): void
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->expects(self::once())
            ->method('beginTransaction')
            ->willReturn(true);

        $execCalls = [];
        $pdo->expects(self::exactly(2))
            ->method('exec')
            ->willReturnCallback(static function (string $sql) use (&$execCalls): int {
                $execCalls[] = $sql;

                return 0;
            });

        $pdo->expects(self::once())
            ->method('commit')
            ->willReturn(true);

        $pdo->method('inTransaction')->willReturn(true);

        $conn = new Connection($pdo, new PostgresDriver());

        $conn->transaction(static function (Connection $outer): void {
            $outer->transaction(static function (): void {
                // no-op
            });
        });

        self::assertSame(['SAVEPOINT psb_tx_2', 'RELEASE SAVEPOINT psb_tx_2'], $execCalls);
    }

    /**
     * Проверяет, что параметры DateTimeInterface безопасно логируются как строки.
     *
     * @see Connection::execute()
     */
    #[Test]
    public function logsDateTimeParamsAsStrings(): void
    {
        $logger = new SpyLogger();

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn' => 'sqlite:///:memory:',
                ],
            ],
        ], $logger);

        $conn = $factory->create('default');

        $conn->execute('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL)');

        $timestamp = new DateTimeImmutable('2024-01-01 00:00:00');

        $conn->execute(
            'INSERT INTO events (created_at) VALUES (:created_at)',
            ['created_at' => $timestamp],
        );

        $this->assertNotEmpty($logger->records);
        $last   = $logger->records[array_key_last($logger->records)];
        $params = $last['context']['params'] ?? [];

        $this->assertSame($timestamp->format(DateTimeInterface::ATOM), $params['created_at'] ?? null);
    }
}
