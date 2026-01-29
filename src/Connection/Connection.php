<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Connection;

use PDO;
use PDOException;
use PDOStatement;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Database\Exception\ReadOnlyException;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function hrtime;
use function is_int;
use function is_scalar;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class Connection implements ConnectionInterface
{
    public function __construct(
        private PDO $pdo,
        private DriverInterface $driver,
        private string $prefix = '',
        private bool $readOnly = false,
        private ?LoggerInterface $logger = null,
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

    public function transaction(callable $fn): mixed
    {
        if ($this->readOnly) {
            throw new ReadOnlyException('Transactions are not allowed for read-only connections.');
        }

        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function schema(): SchemaBuilderInterface
    {
        return new SchemaBuilderFactory()->create($this);
    }

    public function query(): QueryFactory
    {
        return new QueryFactory($this);
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
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
                continue;
            }
            $out[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unserializable]';
        }

        return $out;
    }
}
