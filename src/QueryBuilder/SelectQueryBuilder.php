<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Pagination\Contracts\PaginationResultInterface;
use PhpSoftBox\Pagination\Paginator;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function is_callable;
use function is_numeric;
use function is_string;
use function max;
use function preg_split;
use function strtoupper;
use function trim;

final class SelectQueryBuilder extends AbstractQueryBuilder
{
    use WhereAwareTrait;
    use HavingAwareTrait;

    protected int $paramCounter = 0;

    /**
     * @var list<string>
     */
    private array $columns;

    private ?string $from = null;

    private bool $fromIsRaw = false;

    /**
     * @var list<array{type: 'INNER'|'LEFT'|'RIGHT', table: string, on: string, params: array<string|int, mixed>}>
     */
    private array $joins = [];

    /**
     * @var list<array{column: string, direction: 'ASC'|'DESC'}>
     */
    private array $orderBy = [];

    private ?int $limit  = null;
    private ?int $offset = null;

    /**
     * @var list<string>
     */
    private array $groupBy = [];

    private bool $distinct = false;

    /**
     * @var list<array{type: 'UNION'|'UNION ALL', query: string, params: array<string|int, mixed>}>
     */
    private array $unions = [];

    protected Paginator $paginator;

    /**
     * Технический флаг: билдер сейчас собирается как подзапрос (через callable).
     *
     * Нужен для более предсказуемого поведения fluent API внутри подзапросов.
     */
    private bool $isBuildingSubquery = false;

    /**
     * Params подзапроса из FROM (<subquery>) AS ...
     *
     * @var array<string|int, mixed>
     */
    private array $fromSubqueryParams = [];

    /**
     * @param list<string>|string $columns
     */
    public function __construct(
        ConnectionInterface $connection,
        array|string $columns = ['*'],
        ?Paginator $paginator = null,
    ) {
        parent::__construct($connection);
        $this->columns   = $this->normalizeColumns($columns);
        $this->paginator = $paginator ?? new Paginator();
    }

    /**
     * @param list<string>|string $columns
     * @return list<string>
     */
    private function normalizeColumns(array|string $columns): array
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        $cols = [];
        foreach ($columns as $col) {
            $col = trim((string) $col);
            if ($col === '') {
                continue;
            }
            $cols[] = $col;
        }

        return $cols !== [] ? $cols : ['*'];
    }

    public function from(string|Expression $table): self
    {
        if ($table instanceof Expression) {
            $t = trim((string) $table);
            if ($t !== '') {
                $this->from      = $t;
                $this->fromIsRaw = true;
            }

            return $this;
        }

        $table = trim($table);
        if ($table !== '') {
            $parts = preg_split('/\s+/', $table) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

            $name  = $parts[0] ?? '';
            $alias = $parts[1] ?? null;

            $name = $this->applyTablePrefix($name);

            $this->from      = $alias ? ($name . ' ' . $alias) : $name;
            $this->fromIsRaw = false;
        }

        return $this;
    }

    /**
     * FROM (<subquery>) AS alias
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function fromSubquery(callable|SelectQueryBuilder|Expression|string $subquery, string $alias): self
    {
        $alias = trim($alias);
        if ($alias === '') {
            return $this;
        }

        $compiled = $this->compileSubqueryAny($subquery);
        if (trim($compiled['sql']) === '') {
            return $this;
        }

        $quotedAlias = $this->connection->driver()->createQuoter()->alias($alias);

        $this->from               = '(' . $compiled['sql'] . ') AS ' . $quotedAlias;
        $this->fromIsRaw          = true;
        $this->fromSubqueryParams = $compiled['params'];

        return $this;
    }

    /**
     * INNER JOIN (<subquery>) AS alias ON ...
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function joinSubquery(callable|SelectQueryBuilder|Expression|string $subquery, string $alias, string $on): self
    {
        return $this->addJoinSubquery('INNER', $subquery, $alias, $on);
    }

    /**
     * LEFT JOIN (<subquery>) AS alias ON ...
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function leftJoinSubquery(callable|SelectQueryBuilder|Expression|string $subquery, string $alias, string $on): self
    {
        return $this->addJoinSubquery('LEFT', $subquery, $alias, $on);
    }

    /**
     * RIGHT JOIN (<subquery>) AS alias ON ...
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function rightJoinSubquery(callable|SelectQueryBuilder|Expression|string $subquery, string $alias, string $on): self
    {
        return $this->addJoinSubquery('RIGHT', $subquery, $alias, $on);
    }

    /**
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    private function addJoinSubquery(string $type, callable|SelectQueryBuilder|Expression|string $subquery, string $alias, string $on): self
    {
        $alias = trim($alias);
        $on    = trim($on);
        if ($alias === '' || $on === '') {
            return $this;
        }

        $compiled = $this->compileSubqueryAny($subquery);
        $sql      = trim($compiled['sql']);
        if ($sql === '') {
            return $this;
        }

        $quotedAlias = $this->connection->driver()->createQuoter()->alias($alias);

        $type = strtoupper(trim($type));
        $type = match ($type) {
            'LEFT'  => 'LEFT',
            'RIGHT' => 'RIGHT',
            default => 'INNER',
        };

        $this->joins[] = [
            'type'   => $type,
            'table'  => '(' . $sql . ') AS ' . $quotedAlias,
            'on'     => $on,
            'params' => $compiled['params'],
        ];

        return $this;
    }

    /**
     * Добавляет поле: EXISTS (<subquery>)
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function selectExists(callable|SelectQueryBuilder|Expression|string $subquery, string $alias = 'exists'): self
    {
        return $this->selectExistsInternal($subquery, $alias, not: false);
    }

    /**
     * Добавляет поле: NOT EXISTS (<subquery>)
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function selectNotExists(callable|SelectQueryBuilder|Expression|string $subquery, string $alias = 'not_exists'): self
    {
        return $this->selectExistsInternal($subquery, $alias, not: true);
    }

    /**
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    private function selectExistsInternal(callable|SelectQueryBuilder|Expression|string $subquery, string $alias, bool $not): self
    {
        $alias = trim($alias);
        if ($alias === '') {
            $alias = $not ? 'not_exists' : 'exists';
        }

        $compiled = $this->compileSubqueryAny($subquery);
        $sql      = trim($compiled['sql']);
        if ($sql === '') {
            return $this;
        }

        $quotedAlias = $this->connection->driver()->createQuoter()->alias($alias);

        $expr = ($not ? 'NOT ' : '') . 'EXISTS (' . $sql . ') AS ' . $quotedAlias;

        $this->select($expr);

        if ($compiled['params'] !== []) {
            // Параметры подзапроса в SELECT-выражениях добавляем в общие params.
            $this->selectSubqueryParams = array_merge($this->selectSubqueryParams, $compiled['params']);
        }

        return $this;
    }

    /**
     * Params подзапросов, добавленных через selectExists/selectNotExists.
     *
     * @var array<string|int, mixed>
     */
    private array $selectSubqueryParams = [];

    /**
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function compileSubquery(callable|SelectQueryBuilder|Expression|string $subquery): array
    {
        if ($subquery instanceof Expression) {
            return ['sql' => trim((string) $subquery), 'params' => []];
        }

        if (is_callable($subquery)) {
            $q = new self($this->connection, ['*']);

            $prev                  = $q->isBuildingSubquery;
            $q->isBuildingSubquery = true;
            try {
                $subquery($q);
            } finally {
                $q->isBuildingSubquery = $prev;
            }

            return $q->toSql();
        }

        return ['sql' => trim((string) $subquery), 'params' => []];
    }

    /**
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function compileSubqueryAny(callable|SelectQueryBuilder|Expression|string $subquery): array
    {
        if ($subquery instanceof SelectQueryBuilder) {
            return $subquery->toSql();
        }

        return $this->compileSubquery($subquery);
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toSql(): array
    {
        return $this->connection->driver()->createQueryCompiler()->compileSelect($this);
    }

    /**
     * UNION.
     *
     * @param SelectQueryBuilder|Expression|string|callable(SelectQueryBuilder):void $query
     */
    public function union(SelectQueryBuilder|Expression|string|callable $query): self
    {
        return $this->unionInternal('UNION', $query);
    }

    /**
     * UNION ALL.
     *
     * @param SelectQueryBuilder|Expression|string|callable(SelectQueryBuilder):void $query
     */
    public function unionAll(SelectQueryBuilder|Expression|string|callable $query): self
    {
        return $this->unionInternal('UNION ALL', $query);
    }

    private function unionInternal(string $type, SelectQueryBuilder|Expression|string|callable $query): self
    {
        if ($query instanceof SelectQueryBuilder) {
            $built = $query->toSql();
            $sql   = trim($built['sql']);
            if ($sql === '') {
                return $this;
            }

            $this->unions[] = ['type' => $type, 'query' => $sql, 'params' => $built['params']];

            return $this;
        }

        if ($query instanceof Expression) {
            $sql = trim((string) $query);
            if ($sql === '') {
                return $this;
            }

            $this->unions[] = ['type' => $type, 'query' => $sql, 'params' => []];

            return $this;
        }

        if (is_callable($query)) {
            $compiled = $this->compileSubquery($query);
            if ($compiled['sql'] === '') {
                return $this;
            }

            $this->unions[] = ['type' => $type, 'query' => $compiled['sql'], 'params' => $compiled['params']];

            return $this;
        }

        $sql = trim((string) $query);
        if ($sql === '') {
            return $this;
        }

        $this->unions[] = ['type' => $type, 'query' => $sql, 'params' => []];

        return $this;
    }

    /**
     * Возвращает количество строк для текущего запроса.
     *
     * Важно: для подсчёта сбрасываются ORDER BY / LIMIT / OFFSET.
     */
    public function count(string $column = '*'): int
    {
        $column = trim($column);
        if ($column === '') {
            $column = '*';
        }

        $value = $this->aggregate('COUNT', $column);

        if ($value === null) {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) ((string) $value);
    }

    public function sum(string $column): float|int
    {
        $value = $this->aggregate('SUM', $column);
        if ($value === null) {
            return 0;
        }

        return is_numeric($value) ? (0 + $value) : (float) $value;
    }

    public function avg(string $column): float
    {
        $value = $this->aggregate('AVG', $column);
        if ($value === null) {
            return 0.0;
        }

        return (float) $value;
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Пагинация результата.
     */
    public function paginate(?int $page = null, ?int $perPage = null): PaginationResultInterface
    {
        $resolver  = $this->paginator->contextResolver();
        $paginator = $resolver ? $this->paginator->resolver($resolver) : $this->paginator;

        $pageValue = $page ?? $resolver?->page() ?? 1;
        $pageValue = max(1, $pageValue);

        $perPageValue = $perPage ?? $resolver?->perPage() ?? $paginator->perPage();
        $perPageValue = max(1, $perPageValue);

        $total  = $this->count();
        $offset = ($pageValue - 1) * $perPageValue;

        $items = (clone $this)
            ->limit($perPageValue)
            ->offset($offset)
            ->fetchAll();

        return $paginator->make(
            items: $items,
            total: $total,
            page: $pageValue,
            perPage: $perPageValue,
        );
    }

    /**
     * Общий хелпер для агрегаций.
     */
    private function aggregate(string $func, string $column): mixed
    {
        $func   = strtoupper(trim($func));
        $column = trim($column);
        if ($column === '') {
            return null;
        }

        $q = clone $this;

        // Сбрасываем параметры, которые не должны влиять на агрегат.
        $q->orderBy = [];
        $q->limit   = null;
        $q->offset  = null;

        // DISTINCT на агрегаты обычно влияет, но мы оставляем его как есть (если включён).
        // GROUP BY оставляем как есть (агрегация по группам — ответственность пользователя).

        $q->columns = [$func . '(' . $column . ') AS __agg'];

        $row = $q->fetchOne();
        if ($row === null) {
            return null;
        }

        return $row['__agg'] ?? null;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Делает SELECT DISTINCT.
     */
    public function distinct(bool $enabled = true): self
    {
        $this->distinct = $enabled;

        return $this;
    }

    /**
     * Добавляет колонки в SELECT.
     *
     * @param list<string>|string $columns
     */
    public function select(array|string $columns): self
    {
        $cols = $this->normalizeColumns($columns);

        if ($this->columns === ['*']) {
            $this->columns = $cols;

            return $this;
        }

        foreach ($cols as $c) {
            $this->columns[] = $c;
        }

        return $this;
    }

    /**
     * INNER JOIN (алиас для innerJoin()).
     */
    public function join(string|Expression $table, string $on): self
    {
        return $this->innerJoin($table, $on);
    }

    public function innerJoin(string|Expression $table, string $on): self
    {
        return $this->addJoin('INNER', $table, $on);
    }

    public function leftJoin(string|Expression $table, string $on): self
    {
        return $this->addJoin('LEFT', $table, $on);
    }

    public function rightJoin(string|Expression $table, string $on): self
    {
        return $this->addJoin('RIGHT', $table, $on);
    }

    public function innerJoinRaw(string $sql, string $on): self
    {
        return $this->innerJoin(new Expression($sql), $on);
    }

    public function leftJoinRaw(string $sql, string $on): self
    {
        return $this->leftJoin(new Expression($sql), $on);
    }

    public function rightJoinRaw(string $sql, string $on): self
    {
        return $this->rightJoin(new Expression($sql), $on);
    }

    /**
     * Удобный хелпер для сортировки по убыванию.
     */
    public function latest(string $column = 'created_datetime'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        $direction = strtoupper(trim($direction));
        $direction = $direction === 'DESC' ? 'DESC' : 'ASC';

        $this->orderBy[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    /**
     * @param list<string>|string $columns
     */
    public function groupBy(array|string $columns): self
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $col) {
            $col = trim($col);
            if ($col === '') {
                continue;
            }
            $this->groupBy[] = $col;
        }

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        $built = $this->toSql();

        return $this->connection->fetchAll($built['sql'], $built['params']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(): ?array
    {
        $built = $this->toSql();

        return $this->connection->fetchOne($built['sql'], $built['params']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->fetchOne();
    }

    public function value(string $column): mixed
    {
        $column = trim($column);
        if ($column === '') {
            return null;
        }

        $row = $this->fetchOne();
        if ($row === null) {
            return null;
        }

        return $row[$column] ?? null;
    }

    private function addJoin(string $type, string|Expression $table, string $on): self
    {
        $on = trim($on);
        if ($on === '') {
            return $this;
        }

        $joinTable = null;

        if ($table instanceof Expression) {
            $t = (string) $table;
            if ($t === '') {
                return $this;
            }
            $joinTable = $t;
        } else {
            $t = trim($table);
            if ($t === '') {
                return $this;
            }

            $parts = preg_split('/\s+/', $t) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

            $name  = $parts[0] ?? '';
            $alias = $parts[1] ?? null;

            $name = $this->applyTablePrefix($name);

            $joinTable = $alias ? ($name . ' ' . $alias) : $name;
        }

        $type = strtoupper(trim($type));
        $type = match ($type) {
            'LEFT'  => 'LEFT',
            'RIGHT' => 'RIGHT',
            default => 'INNER',
        };

        $this->joins[] = [
            'type'   => $type,
            'table'  => $joinTable,
            'on'     => $on,
            'params' => [],
        ];

        return $this;
    }

    /**
     * Добавляет WHERE EXISTS (...).
     *
     * @param string|Expression|callable(SelectQueryBuilder):void $subquery
     */
    public function whereExists(string|Expression|callable $subquery): self
    {
        return $this->whereExistsInternal('AND', $subquery, not: false);
    }

    /**
     * OR WHERE EXISTS (...)
     *
     * @param string|Expression|callable(SelectQueryBuilder):void $subquery
     */
    public function orWhereExists(string|Expression|callable $subquery): self
    {
        return $this->whereExistsInternal('OR', $subquery, not: false);
    }

    /**
     * WHERE NOT EXISTS (...)
     *
     * @param string|Expression|callable(SelectQueryBuilder):void $subquery
     */
    public function whereNotExists(string|Expression|callable $subquery): self
    {
        return $this->whereExistsInternal('AND', $subquery, not: true);
    }

    /**
     * OR WHERE NOT EXISTS (...)
     *
     * @param string|Expression|callable(SelectQueryBuilder):void $subquery
     */
    public function orWhereNotExists(string|Expression|callable $subquery): self
    {
        return $this->whereExistsInternal('OR', $subquery, not: true);
    }

    private function whereExistsInternal(string $boolean, string|Expression|callable $subquery, bool $not): self
    {
        $compiled = $this->compileSubquery($subquery);
        if ($compiled['sql'] === '') {
            return $this;
        }

        $sql = ($not ? 'NOT ' : '') . 'EXISTS (' . $compiled['sql'] . ')';

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $compiled['params'],
        ]);

        return $this;
    }

    // ---
    // AST getters for compiler
    // ---

    /** @internal @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @internal */
    public function fromValue(): ?string
    {
        return $this->from;
    }

    /** @internal @return list<array{type: 'INNER'|'LEFT'|'RIGHT', table: string, on: string, params: array<string|int, mixed>}> */
    public function joins(): array
    {
        return $this->joins;
    }

    /** @internal @return list<array{column: string, direction: 'ASC'|'DESC'}> */
    public function orderByClauses(): array
    {
        return $this->orderBy;
    }

    /** @internal */
    public function limitValue(): ?int
    {
        return $this->limit;
    }

    /** @internal */
    public function offsetValue(): ?int
    {
        return $this->offset;
    }

    /** @internal @return list<string> */
    public function groupByColumns(): array
    {
        return $this->groupBy;
    }

    /** @internal */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /** @internal @return list<array{type: 'UNION'|'UNION ALL', query: string, params: array<string|int, mixed>}> */
    public function unions(): array
    {
        return $this->unions;
    }

    /** @internal @return array<string|int, mixed> */
    public function fromSubqueryParams(): array
    {
        return $this->fromSubqueryParams;
    }

    /** @internal @return array<string|int, mixed> */
    public function selectSubqueryParams(): array
    {
        return $this->selectSubqueryParams;
    }

    /** @internal */
    public function fromIsRaw(): bool
    {
        return $this->fromIsRaw;
    }

    /**
     * Возвращает клон текущего билдера со сброшенными ORDER BY / LIMIT / OFFSET.
     *
     * Это нужно для корректной компиляции UNION-запросов: во многих СУБД ORDER BY/LIMIT/OFFSET
     * применяются к результату UNION целиком, поэтому базовый UNION-запрос собираем без paging,
     * а потом при необходимости оборачиваем его в SELECT * FROM (...).
     *
     * @internal
     */
    public function resetPaginationAndOrderForUnion(): self
    {
        $clone          = clone $this;
        $clone->orderBy = [];
        $clone->limit   = null;
        $clone->offset  = null;

        return $clone;
    }
}
