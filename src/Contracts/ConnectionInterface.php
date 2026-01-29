<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Contracts;

use PDO;
use PhpSoftBox\Database\IsolationLevelEnum;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use Psr\Log\LoggerInterface;

interface ConnectionInterface
{
    public function pdo(): PDO;

    /**
     * Выполняет запрос и возвращает все строки в виде массива.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * Выполняет запрос и возвращает первую строку или null.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array;

    /**
     * Выполняет запрос и возвращает количество затронутых строк.
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Выполняет callback внутри транзакции.
     *
     * @param IsolationLevelEnum|null $isolationLevel Уровень изоляции (применяется только для внешней транзакции).
     */
    public function transaction(callable $fn, ?IsolationLevelEnum $isolationLevel = null): mixed;

    /**
     * Последний идентификатор вставленной записи.
     */
    public function lastInsertId(?string $name = null): string;

    /**
     * Префикс таблиц для этого подключения.
     */
    public function prefix(): string;

    /**
     * Возвращает имя таблицы с префиксом.
     */
    public function table(string $name): string;

    public function isReadOnly(): bool;

    /**
     * Schema builder (DDL/изменения схемы) для этого подключения.
     *
     * Важно: builder должен учитывать текущий драйвер и префикс таблиц.
     */
    public function schema(): SchemaBuilderInterface;

    public function logger(): ?LoggerInterface;

    /**
     * Создаёт фабрику query builder'ов, привязанную к этому подключению.
     */
    public function query(): QueryFactory;

    /**
     * Возвращает DriverInterface для текущего подключения.
     *
     * Нужен для SQL-диалекта (quoting, нюансы синтаксиса) без попыток угадать драйвер через PDO.
     */
    public function driver(): DriverInterface;
}
