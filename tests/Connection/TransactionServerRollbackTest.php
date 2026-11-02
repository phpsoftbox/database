<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Connection;

use DomainException;
use PDO;
use PDOException;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\Tests\Utils\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function implode;

/**
 * Сервер откатил транзакцию целиком посреди вложенного уровня (deadlock, на MariaDB со snapshot isolation —
 * ER_CHECKREAD 1020): точек сохранения больше нет. Здесь это имитирует ROLLBACK в обход Connection.
 */
#[CoversClass(Connection::class)]
#[CoversMethod(Connection::class, 'transaction')]
final class TransactionServerRollbackTest extends TestCase
{
    /**
     * Проверим, что вызывающий получает исходную ошибку вложенного уровня, а не «no such savepoint»: внешний
     * уровень откатывается без SQL, изменения не сохраняются, подключение работает дальше, причина в журнале.
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function nestedErrorAfterServerRollbackReachesCallerUnchanged(): void
    {
        $logger = new SpyLogger();

        [$pdo, $connection] = $this->connection($logger);

        try {
            $connection->transaction(static function (Connection $outer) use ($pdo): void {
                $outer->execute('INSERT INTO items (name) VALUES (\'outer\')');
                $outer->transaction(static function () use ($pdo): void {
                    // The server rolls the whole transaction back and the statement fails.
                    $pdo->exec('ROLLBACK');

                    throw new DomainException('Record has changed since last read');
                });
            });
            self::fail('The original error was expected.');
        } catch (DomainException $error) {
            self::assertSame('Record has changed since last read', $error->getMessage());
        }

        self::assertSame([], $connection->fetchAll('SELECT name FROM items'));
        self::assertStringContainsString('rolled back by the database server', implode("\n", array_column($logger->records, 'message')));

        // The connection is usable again.
        $connection->transaction(static fn (Connection $tx): int => $tx->execute('INSERT INTO items (name) VALUES (\'next\')'));
        self::assertSame([['name' => 'next']], $connection->fetchAll('SELECT name FROM items'));
    }

    /**
     * Проверим, что внешний уровень, проглотивший ошибку вложенного, не фиксирует откаченную сервером транзакцию
     * молча: commit бросает исключение.
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function outerLevelCannotCommitTransactionRolledBackByServer(): void
    {
        [$pdo, $connection] = $this->connection(new SpyLogger());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('nothing was committed');

        $connection->transaction(static function (Connection $outer) use ($pdo): void {
            $outer->execute('INSERT INTO items (name) VALUES (\'outer\')');
            try {
                $outer->transaction(static function () use ($pdo): void {
                    $pdo->exec('ROLLBACK');

                    throw new DomainException('Deadlock found when trying to get lock');
                });
            } catch (DomainException) {
                // The caller ignores the failure and goes on.
            }
        });
    }

    /**
     * Проверим путь MariaDB: PDO ещё считает транзакцию открытой, ROLLBACK TO SAVEPOINT падает с 1305 — вызывающий
     * получает исходную ошибку, транзакция откатывается целиком.
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function failedSavepointRollbackKeepsOriginalError(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->method('exec')->willReturnCallback(static function (string $sql): int {
            if ($sql === 'ROLLBACK TO SAVEPOINT psb_tx_2') {
                throw new PDOException('SQLSTATE[42000]: 1305 SAVEPOINT psb_tx_2 does not exist');
            }

            return 0;
        });
        $connection = new Connection($pdo, new MariaDbDriver());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Record has changed since last read');

        $connection->transaction(static function (Connection $outer): void {
            $outer->transaction(static function (): void {
                throw new DomainException('Record has changed since last read');
            });
        });
    }

    /** @return array{0: PDO, 1: Connection} */
    private function connection(SpyLogger $logger): array
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection = new Connection($pdo, new SqliteDriver(), logger: $logger);

        $connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        return [$pdo, $connection];
    }
}
