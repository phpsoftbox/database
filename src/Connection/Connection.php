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
use PhpSoftBox\Database\QueryBuilder\CompiledQuery;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use PhpSoftBox\Pagination\Paginator as PaginationPaginator;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_exists;
use function array_values;
use function count;
use function hrtime;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function json_encode;
use function ltrim;
use function preg_match_all;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtoupper;

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
            $compiled = new CompiledQuery($sql, $params);

            $prepared = $this->preparePositionalBindings($compiled);
            $prepared = $this->inlineBindingsForUnsupportedStatements($prepared);

            $stmt = $this->pdo->prepare($prepared->sql);
            $stmt->execute($this->normalizePdoParams($prepared->bindings));

            $elapsedMs  = (hrtime(true) - $start) / 1_000_000;
            $logContext = [
                'sql'        => $prepared->sql,
                'params'     => $this->stringifyParams($prepared->bindings),
                'elapsed_ms' => $elapsedMs,
            ];
            if ($prepared->sql !== $compiled->sql) {
                $logContext['source_sql']    = $compiled->sql;
                $logContext['source_params'] = $this->stringifyParams($compiled->bindings);
            }
            $this->logger?->debug('DB query executed', [
                ...$logContext,
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
     * Преобразует named placeholders в positional `?`, сохраняя внешний API с именованными параметрами.
     *
     * Если SQL/params не подходят для безопасной конвертации (смешанные типы ключей, missing placeholders,
     * лишние именованные параметры), возвращается исходный набор.
     */
    private function preparePositionalBindings(CompiledQuery $query): CompiledQuery
    {
        $sql    = $query->sql;
        $params = $query->bindings;

        if ($params === []) {
            return $query;
        }

        $hasPositional = false;
        /** @var array<string, mixed> $named */
        $named = [];
        foreach ($params as $k => $v) {
            if (is_int($k)) {
                $hasPositional = true;
                continue;
            }

            $name = ltrim((string) $k, ':');
            if ($name === '') {
                continue;
            }
            $named[$name] = $v;
        }

        // Не смешиваем режимы и не трогаем уже позиционные запросы.
        if ($hasPositional || $named === []) {
            return $query;
        }

        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        /** @var list<string> $placeholders */
        $placeholders = $matches[1] ?? [];
        if ($placeholders === []) {
            return $query;
        }

        /** @var array<string, true> $usedNames */
        $usedNames = [];
        foreach ($placeholders as $name) {
            if (!array_key_exists($name, $named)) {
                return $query;
            }
            $usedNames[$name] = true;
        }

        // Сохраняем прежнюю строгость: лишние named-параметры не скрываем конвертацией.
        if (count($named) !== count($usedNames)) {
            return $query;
        }

        /** @var list<mixed> $orderedValues */
        $orderedValues = [];
        $rewrittenSql  = preg_replace_callback(
            '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $m) use (&$orderedValues, $named): string {
                $name = (string) ($m[1] ?? '');
                if (!array_key_exists($name, $named)) {
                    return (string) ($m[0] ?? '');
                }

                $orderedValues[] = $named[$name];

                return '?';
            },
            $sql,
        );

        if (!is_string($rewrittenSql)) {
            return $query;
        }

        return new CompiledQuery($rewrittenSql, $orderedValues);
    }

    /**
     * В отдельных SQL-операторах (например, SHOW в MariaDB) PDO-плейсхолдеры синтаксически не поддерживаются.
     * Для таких случаев инлайнит positional значения в SQL через безопасное quoting.
     */
    private function inlineBindingsForUnsupportedStatements(CompiledQuery $query): CompiledQuery
    {
        if (!$this->shouldInlinePositionalBindings($query)) {
            return $query;
        }

        $inlinedSql = $this->inlinePositionalBindings($query->sql, $query->bindings);
        if ($inlinedSql === $query->sql) {
            return $query;
        }

        return new CompiledQuery($inlinedSql, []);
    }

    private function shouldInlinePositionalBindings(CompiledQuery $query): bool
    {
        if ($query->bindings === []) {
            return false;
        }

        if (!str_contains($query->sql, '?')) {
            return false;
        }

        if (!$this->isShowStatement($query->sql)) {
            return false;
        }

        foreach ($query->bindings as $key => $_value) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }

    private function isShowStatement(string $sql): bool
    {
        return str_starts_with(strtoupper(ltrim($sql)), 'SHOW ');
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    private function inlinePositionalBindings(string $sql, array $bindings): string
    {
        $normalized = array_values($this->normalizePdoParams($bindings));
        if ($normalized === []) {
            return $sql;
        }

        $index        = 0;
        $rewrittenSql = preg_replace_callback('/\?/', function (array $match) use (&$index, $normalized): string {
            if (!array_key_exists($index, $normalized)) {
                return (string) ($match[0] ?? '?');
            }

            $value = $normalized[$index];
            $index++;

            return $this->toSqlLiteral($value);
        }, $sql);

        if (!is_string($rewrittenSql)) {
            return $sql;
        }

        if ($index !== count($normalized)) {
            return $sql;
        }

        return $rewrittenSql;
    }

    private function toSqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $value   = is_string($encoded) ? $encoded : (string) $value;
        }

        $quoted = $this->pdo->quote($value);
        if (is_string($quoted)) {
            return $quoted;
        }

        return "'" . str_replace("'", "''", $value) . "'";
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
                $v = $this->formatDateTimeParam($v);
            } elseif (is_bool($v)) {
                $v = $this->formatBoolParam($v);
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
                $out[$k] = $this->formatDateTimeParam($v);
                continue;
            }
            if (is_bool($v)) {
                $out[$k] = $this->formatBoolParam($v);
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

    private function formatDateTimeParam(DateTimeInterface $value): string
    {
        if ($this->driver->name() === 'mariadb') {
            return $value->format('Y-m-d H:i:s');
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    private function formatBoolParam(bool $value): bool|int
    {
        if ($this->driver->name() === 'mariadb') {
            return $value ? 1 : 0;
        }

        return $value;
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
