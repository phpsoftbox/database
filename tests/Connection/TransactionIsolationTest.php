<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Connection;

use PDO;
use PDOException;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\IsolationLevelEnum;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Connection::class)]
#[CoversMethod(Connection::class, 'transaction')]
final class TransactionIsolationTest extends TestCase
{
    /**
     * Проверим, что на MariaDB транзакция с уровнем изоляции выполняется (SET TRANSACTION до BEGIN).
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function mariaDbTransactionWithIsolationLevelSucceeds(): void
    {
        try {
            $connection = IntegrationDatabases::mariadbDatabase()->connection();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $result = $connection->transaction(
            static fn (Connection $tx): mixed => $tx->fetchOne('SELECT 1 AS one')['one'] ?? null,
            IsolationLevelEnum::READ_COMMITTED,
        );

        self::assertSame(1, (int) $result);
    }

    /**
     * Проверим, что на PostgreSQL уровень изоляции применяется к текущей транзакции (SET TRANSACTION после BEGIN).
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function postgresTransactionAppliesIsolationLevel(): void
    {
        try {
            $connection = IntegrationDatabases::postgresDatabase()->connection();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $level = $connection->transaction(
            static fn (Connection $tx): mixed => $tx->fetchOne('SHOW transaction_isolation')['transaction_isolation'] ?? null,
            IsolationLevelEnum::REPEATABLE_READ,
        );

        self::assertSame('repeatable read', $level);
    }

    /**
     * Проверим, что на MySQL/MariaDB уровень изоляции выставляется до beginTransaction().
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function mySqlFamilySetsIsolationBeforeBegin(): void
    {
        $calls = [];

        $pdo = self::createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$calls): int {
            $calls[] = $sql;

            return 0;
        });
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'BEGIN';

            return true;
        });
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        new Connection($pdo, new MariaDbDriver())->transaction(static function (): void {
            // no-op
        }, IsolationLevelEnum::SERIALIZABLE);

        self::assertSame(['SET TRANSACTION ISOLATION LEVEL SERIALIZABLE', 'BEGIN'], $calls);
    }

    /**
     * Проверим, что ошибка PDO при старте транзакции оборачивается в QueryException.
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function wrapsBeginFailureIntoQueryException(): void
    {
        $pdo = self::createStub(PDO::class);
        $pdo->method('exec')->willThrowException(new PDOException('isolation failed'));
        $pdo->method('inTransaction')->willReturn(false);

        $this->expectException(QueryException::class);

        new Connection($pdo, new MariaDbDriver())->transaction(static function (): void {
            // no-op
        }, IsolationLevelEnum::SERIALIZABLE);
    }
}
