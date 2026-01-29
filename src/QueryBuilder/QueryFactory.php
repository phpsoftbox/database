<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Pagination\Paginator as PaginationPaginator;

/**
 * Фабрика для создания query builder'ов.
 *
 * Использование:
 *  $conn->query()->select()->from('users')->where(...)->fetchAll();
 */
final readonly class QueryFactory
{
    public function __construct(
        private ConnectionInterface $connection,
        private ?PaginationPaginator $paginator = null,
    ) {
    }

    public function raw(string $sql): Expression
    {
        return new Expression($sql);
    }

    /**
     * Создаёт билдер SELECT.
     *
     * @param list<string>|string $columns
     */
    public function select(array|string $columns = ['*']): SelectQueryBuilder
    {
        return new SelectQueryBuilder($this->connection, $columns, $this->paginator);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data = []): InsertQueryBuilder
    {
        return new InsertQueryBuilder($this->connection, $table, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data = []): UpdateQueryBuilder
    {
        return new UpdateQueryBuilder($this->connection, $table, $data);
    }

    public function delete(string $table): DeleteQueryBuilder
    {
        return new DeleteQueryBuilder($this->connection, $table);
    }
}
