<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Connection;

use DateTimeInterface;
use PDO;
use PDOException;
use PDOStatement;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\Exception\ReadOnlyException;
use PhpSoftBox\Database\IsolationLevelEnum;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use PhpSoftBox\Pagination\Paginator as PaginationPaginator;
use Psr\Log\LoggerInterface;
use Throwable;

use function hrtime;
use function is_int;
use function is_scalar;
use function json_encode;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class Connection implements ConnectionInterface
{
    private int $transactionLevel = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DriverInterface $driver,
        private readonly string $prefix = '',
        private readonly bool $readOnly = false,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?PaginationPaginator $paginator = null,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function driver(): DriverInterface
    {
        return $this->driver;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepareAndExecute($sql, $params);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        if ($this->readOnly) {
            throw new ReadOnlyException('This connection is read-only.');
        }

        $stmt = $this->prepareAndExecute($sql, $params);

        return $stmt->rowCount();
    }

    public function transaction(callable $fn, ?IsolationLevelEnum $isolationLevel = null): mixed
    {
        if ($this->readOnly) {
            throw new ReadOnlyException('Transactions are not allowed for read-only connections.');
        }

        try {
            $this->beginTransaction($isolationLevel);
            $result = $fn($this);
            $this->commitTransaction();

            return $result;
        } catch (Throwable $e) {
            $this->rollbackTransaction();

            throw $e;
        }
    }

    /**
     * Ручной старт транзакции (используется тестовыми инструментами).
     */
    public function beginTransactionManual(?IsolationLevelEnum $isolationLevel = null): bool
    {
        return $this->beginTransaction($isolationLevel);
    }

    /**
     * Ручной откат транзакции (используется тестовыми инструментами).
     */
    public function rollbackTransactionManual(): bool
    {
        return $this->rollbackTransaction();
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }

    public function schema(): SchemaBuilderInterface
    {
        return new SchemaBuilderFactory()->create($this);
    }

    public function query(): QueryFactory
    {
        return new QueryFactory($this, $this->paginator);
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $start = hrtime(true);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->normalizePdoParams($params));

            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            $this->logger?->debug('DB query executed', [
                'sql'        => $sql,
                'params'     => $this->stringifyParams($params),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $stmt;
        } catch (PDOException $e) {
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;
            $this->logger?->error('DB query failed', [
                'sql'        => $sql,
                'params'     => $this->stringifyParams($params),
                'elapsed_ms' => $elapsedMs,
                'exception'  => $e,
            ]);

            throw new QueryException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * PDO принимает именованные параметры с двоеточием: [':name' => value].
     * При этом во всём компоненте мы храним params в удобном виде: ['name' => value].
     *
     * @param array<string|int, mixed> $params
     * @return array<string|int, mixed>
     */
    private function normalizePdoParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $v = $v->format(DateTimeInterface::ATOM);
            }

            if (is_int($k)) {
                $out[$k] = $v;
                continue;
            }

            $key = (string) $k;
            if ($key !== '' && $key[0] !== ':') {
                $key = ':' . $key;
            }

            $out[$key] = $v;
        }

        return $out;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string|int, scalar|null>
     */
    private function stringifyParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $out[$k] = $v->format(DateTimeInterface::ATOM);
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
                continue;
            }
            $out[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unserializable]';
        }

        return $out;
    }

    private function beginTransaction(?IsolationLevelEnum $isolationLevel = null): bool
    {
        ++$this->transactionLevel;

        if ($this->transactionLevel === 1) {
            $this->logger?->info('Begin transaction', [
                'isolation' => $isolationLevel?->value,
            ]);

            try {
                $this->pdo->beginTransaction();
                if ($isolationLevel !== null) {
                    $this->setIsolationLevel($isolationLevel);
                }

                return true;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->transactionLevel = 0;

                throw $e;
            }
        }

        try {
            $this->createSavepoint($this->transactionLevel);

            return true;
        } catch (Throwable $e) {
            --$this->transactionLevel;

            throw $e;
        }
    }

    private function commitTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->logger?->warning(
                sprintf(
                    'Attempt to commit a transaction that has not yet begun. Transaction level: %d',
                    $this->transactionLevel,
                ),
            );

            if ($this->transactionLevel === 0) {
                return false;
            }

            $this->transactionLevel = 0;

            return true;
        }

        --$this->transactionLevel;

        if ($this->transactionLevel === 0) {
            $this->logger?->info('Commit transaction');

            return $this->pdo->commit();
        }

        $this->releaseSavepoint($this->transactionLevel + 1);

        return true;
    }

    private function rollbackTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            $this->logger?->warning(
                sprintf(
                    'Attempt to rollback a transaction that has not yet begun. Transaction level: %d',
                    $this->transactionLevel,
                ),
            );

            $this->transactionLevel = 0;

            return false;
        }

        --$this->transactionLevel;

        if ($this->transactionLevel === 0) {
            $this->logger?->info('Rollback transaction');

            return $this->pdo->rollBack();
        }

        $this->rollbackSavepoint($this->transactionLevel + 1);

        return true;
    }

    private function setIsolationLevel(IsolationLevelEnum $isolationLevel): void
    {
        $driver = $this->driver->name();
        if ($driver === 'sqlite') {
            $value = $isolationLevel === IsolationLevelEnum::READ_UNCOMMITTED ? '1' : '0';
            $this->pdo->exec('PRAGMA read_uncommitted = ' . $value);

            return;
        }

        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel->value);
    }

    private function savepointName(int $level): string
    {
        return 'psb_tx_' . $level;
    }

    private function createSavepoint(int $level): void
    {
        $this->pdo->exec('SAVEPOINT ' . $this->savepointName($level));
    }

    private function releaseSavepoint(int $level): void
    {
        $this->pdo->exec('RELEASE SAVEPOINT ' . $this->savepointName($level));
    }

    private function rollbackSavepoint(int $level): void
    {
        $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $this->savepointName($level));
    }
}
