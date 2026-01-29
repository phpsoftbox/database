<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use InvalidArgumentException;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\Postgres\FullText\PgFullTextOptions;
use PhpSoftBox\Pagination\Contracts\PaginationResultInterface;
use PhpSoftBox\Pagination\Paginator;

use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function implode;
use function is_callable;
use function is_numeric;
use function is_string;
use function max;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_replace;
use function strtoupper;
use function trim;

final class SelectQueryBuilder extends AbstractQueryBuilder
{
    use WhereAwareTrait;
    use HavingAwareTrait;

    protected int $paramCounter = 0;

    /**
     * @var list<string|Expression>
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

    private ?string $lockClause = null;

    private int $pgFullTextParamCounter = 0;

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
     * @param list<string|Expression>|string|Expression $columns
     */
    public function __construct(
        ConnectionInterface $connection,
        array|string|Expression $columns = ['*'],
        ?Paginator $paginator = null,
    ) {
        parent::__construct($connection);
        $this->columns   = $this->normalizeColumns($columns);
        $this->paginator = $paginator ?? new Paginator();
    }

    /**
     * @param list<string|Expression>|string|Expression $columns
     * @return list<string|Expression>
     */
    private function normalizeColumns(array|string|Expression $columns): array
    {
        if (is_string($columns) || $columns instanceof Expression) {
            $columns = [$columns];
        }

        $cols = [];
        foreach ($columns as $col) {
            if ($col instanceof Expression) {
                $value = trim((string) $col);
                if ($value === '') {
                    continue;
                }

                $cols[] = $col;
                continue;
            }

            $value = trim((string) $col);
            if ($value === '') {
                continue;
            }

            $this->assertSimpleSelectString($value);

            $cols[] = $value;
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

        $this->selectRaw($expr);

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
     * Возвращает скомпилированный запрос (named SQL + named bindings).
     */
    public function compile(): CompiledQuery
    {
        return $this->connection->driver()->createQueryCompiler()->compileSelect($this);
    }

    /**
     * Legacy API.
     *
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toSql(): array
    {
        return $this->compile()->toArray();
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

    /**
     * Проверяет наличие хотя бы одной строки для текущего запроса.
     *
     * Важно: для EXISTS-проверки сбрасываются ORDER BY / OFFSET,
     * и выставляется LIMIT 1.
     */
    public function exists(): bool
    {
        $q = clone $this;

        $q->orderBy              = [];
        $q->offset               = null;
        $q->limit                = 1;
        $q->columns              = ['1'];
        $q->selectSubqueryParams = [];

        return $q->fetchOne() !== null;
    }

    /**
     * Проверяет отсутствие строк для текущего запроса.
     *
     * Это инверсия exists() с теми же оптимизациями SQL.
     */
    public function notExists(): bool
    {
        return !$this->exists();
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
        $q->orderBy              = [];
        $q->limit                = null;
        $q->offset               = null;
        $q->selectSubqueryParams = [];

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
     * Устанавливает lock-clause в конец SELECT (например: FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE).
     */
    public function lock(?string $lockClause): self
    {
        if ($lockClause === null) {
            $this->lockClause = null;

            return $this;
        }

        $lockClause = trim($lockClause);
        if ($lockClause === '') {
            $this->lockClause = null;

            return $this;
        }

        if ($this->isUnsafeLockClause($lockClause)) {
            throw new InvalidArgumentException(
                'Unsafe lock clause. Use a plain lock expression without comments or statement separators.',
            );
        }

        $normalized       = preg_replace('/\s+/', ' ', $lockClause);
        $this->lockClause = $normalized !== null ? trim($normalized) : $lockClause;

        return $this;
    }

    public function clearLock(): self
    {
        return $this->lock(null);
    }

    /**
     * Добавляет row-level lock FOR UPDATE к SELECT.
     */
    public function lockForUpdate(bool $enabled = true): self
    {
        return $enabled ? $this->lock('FOR UPDATE') : $this->clearLock();
    }

    /**
     * Добавляет row-level lock FOR SHARE к SELECT.
     */
    public function lockForShare(bool $enabled = true): self
    {
        return $enabled ? $this->lock('FOR SHARE') : $this->clearLock();
    }

    /**
     * Добавляет lock LOCK IN SHARE MODE к SELECT.
     */
    public function lockInShareMode(bool $enabled = true): self
    {
        return $enabled ? $this->lock('LOCK IN SHARE MODE') : $this->clearLock();
    }

    /**
     * Добавляет row-level lock FOR NO KEY UPDATE к SELECT (PostgreSQL).
     */
    public function lockForNoKeyUpdate(bool $enabled = true): self
    {
        return $enabled ? $this->lock('FOR NO KEY UPDATE') : $this->clearLock();
    }

    /**
     * Добавляет row-level lock FOR KEY SHARE к SELECT (PostgreSQL).
     */
    public function lockForKeyShare(bool $enabled = true): self
    {
        return $enabled ? $this->lock('FOR KEY SHARE') : $this->clearLock();
    }

    /**
     * Добавляет или удаляет суффикс NOWAIT к текущему lock clause.
     */
    public function lockNoWait(bool $enabled = true): self
    {
        return $this->toggleLockSuffix('NOWAIT', $enabled);
    }

    /**
     * Добавляет или удаляет суффикс SKIP LOCKED к текущему lock clause.
     */
    public function lockSkipLocked(bool $enabled = true): self
    {
        return $this->toggleLockSuffix('SKIP LOCKED', $enabled);
    }

    /**
     * Добавляет суффикс WAIT n к текущему lock clause (MariaDB).
     */
    public function lockWait(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('lock wait seconds must be greater than or equal to 0.');
        }

        $clause = $this->lockClause ?? 'FOR UPDATE';
        $clause = $this->removeLockOption($clause, 'NOWAIT');
        $clause = $this->removeLockOption($clause, 'SKIP LOCKED');
        $clause = $this->removeWaitClause($clause);
        $clause = trim($clause . ' WAIT ' . $seconds);

        $this->lockClause = $clause;

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
     * @param list<string|Expression>|string|Expression $columns
     */
    public function select(array|string|Expression $columns): self
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
     * Добавляет raw-выражение в SELECT.
     *
     * Для SQL-функций/выражений используйте selectRaw() или new Expression(...).
     */
    public function selectRaw(string|Expression $expression, array $params = []): self
    {
        if ($expression instanceof Expression) {
            $value = trim((string) $expression);
            if ($value === '') {
                return $this;
            }

            $this->mergeSelectExpressionParams($params);

            if ($this->columns === ['*']) {
                $this->columns = [$expression];

                return $this;
            }

            $this->columns[] = $expression;

            return $this;
        }

        $value = trim($expression);
        if ($value === '') {
            return $this;
        }

        $this->mergeSelectExpressionParams($params);

        if ($this->columns === ['*']) {
            $this->columns = [$value];

            return $this;
        }

        $this->columns[] = $value;

        return $this;
    }

    /**
     * Алиас к selectRaw() для явного append-сценария.
     */
    public function addSelectRaw(string|Expression $expression, array $params = []): self
    {
        return $this->selectRaw($expression, $params);
    }

    public function selectCount(string $column = '*', string $alias = 'count'): self
    {
        return $this->selectAggregate('COUNT', $column, $alias);
    }

    public function selectSum(string $column, string $alias): self
    {
        return $this->selectAggregate('SUM', $column, $alias);
    }

    public function selectAvg(string $column, string $alias): self
    {
        return $this->selectAggregate('AVG', $column, $alias);
    }

    public function selectMin(string $column, string $alias): self
    {
        return $this->selectAggregate('MIN', $column, $alias);
    }

    public function selectMax(string $column, string $alias): self
    {
        return $this->selectAggregate('MAX', $column, $alias);
    }

    public function wherePgFullText(string|Expression $vector, string $query, ?PgFullTextOptions $options = null): self
    {
        return $this->wherePgFullTextInternal('AND', $vector, $query, $options);
    }

    public function orWherePgFullText(string|Expression $vector, string $query, ?PgFullTextOptions $options = null): self
    {
        return $this->wherePgFullTextInternal('OR', $vector, $query, $options);
    }

    public function selectPgFullTextRank(
        string|Expression $vector,
        string $query,
        string $alias = 'search_rank',
        ?PgFullTextOptions $options = null,
    ): self {
        $this->assertPostgresDriverForFullText();

        $options ??= new PgFullTextOptions();
        $query = trim($query);
        $alias = trim($alias);
        if ($alias === '') {
            return $this;
        }

        if ($query === '' && $options->skipEmptyQuery) {
            return $this->selectRaw('0 AS ' . $alias);
        }

        $paramName = $this->nextPgFullTextParamName();
        $rankArgs  = [
            $this->pgFullTextVectorSql($vector),
            $this->pgFullTextQuerySql($options, ':' . $paramName),
        ];

        if ($options->normalization !== null) {
            $rankArgs[] = (string) $options->normalization;
        }

        return $this->selectRaw(
            $options->rankFunctionSql() . '(' . implode(', ', $rankArgs) . ') AS ' . $alias,
            [$paramName => $query],
        );
    }

    public function selectPgFullTextHeadline(
        string|Expression $document,
        string $query,
        string $alias = 'search_headline',
        ?PgFullTextOptions $options = null,
        ?string $headlineOptions = null,
    ): self {
        $this->assertPostgresDriverForFullText();

        $options ??= new PgFullTextOptions();
        $query = trim($query);
        $alias = trim($alias);
        if ($alias === '') {
            return $this;
        }

        if ($query === '' && $options->skipEmptyQuery) {
            return $this->selectRaw("'' AS " . $alias);
        }

        $paramName = $this->nextPgFullTextParamName();
        $args      = [
            $options->configSql(),
            $this->pgFullTextVectorSql($document),
            $this->pgFullTextQuerySql($options, ':' . $paramName),
        ];

        $headlineOptions = $headlineOptions !== null ? trim($headlineOptions) : '';
        if ($headlineOptions !== '') {
            $args[] = $this->quoteSqlLiteral($headlineOptions);
        }

        return $this->selectRaw(
            'ts_headline(' . implode(', ', $args) . ') AS ' . $alias,
            [$paramName => $query],
        );
    }

    public function orderByPgFullTextRank(string $alias = 'search_rank', string $direction = 'DESC'): self
    {
        $this->assertPostgresDriverForFullText();

        return $this->orderBy($alias, $direction);
    }

    private function selectAggregate(string $function, string $column, string $alias): self
    {
        $function = strtoupper(trim($function));
        $column   = trim($column);
        $alias    = trim($alias);

        if ($function === '' || $column === '' || $alias === '') {
            return $this;
        }

        $columnSql = $column === '*' ? '*' : $this->quoteDottedIdent($column);

        return $this->selectRaw($function . '(' . $columnSql . ') AS ' . $alias);
    }

    private function wherePgFullTextInternal(
        string $boolean,
        string|Expression $vector,
        string $query,
        ?PgFullTextOptions $options = null,
    ): self {
        $this->assertPostgresDriverForFullText();

        $options ??= new PgFullTextOptions();
        $query = trim($query);
        if ($query === '' && $options->skipEmptyQuery) {
            return $this;
        }

        $paramName = $this->nextPgFullTextParamName();
        $sql       = $this->pgFullTextVectorSql($vector) . ' @@ ' . $this->pgFullTextQuerySql($options, ':' . $paramName);

        if ($boolean === 'OR') {
            return $this->orWhereRaw($sql, [$paramName => $query]);
        }

        return $this->whereRaw($sql, [$paramName => $query]);
    }

    private function assertPostgresDriverForFullText(): void
    {
        $driver = $this->connection->driver()->name();
        if ($driver === 'postgres') {
            return;
        }

        throw new ConfigurationException(
            'PostgreSQL full-text search query methods are supported only by postgres driver, current driver: ' . $driver . '.',
        );
    }

    private function pgFullTextVectorSql(string|Expression $vector): string
    {
        if ($vector instanceof Expression) {
            return trim((string) $vector);
        }

        return $this->quoteDottedIdent($vector);
    }

    private function pgFullTextQuerySql(PgFullTextOptions $options, string $placeholder): string
    {
        return $options->queryFunctionSql() . '(' . $options->configSql() . ', ' . $placeholder . ')';
    }

    private function quoteSqlLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function nextPgFullTextParamName(): string
    {
        $this->pgFullTextParamCounter++;

        return '__pg_fts_query_' . $this->pgFullTextParamCounter;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function mergeSelectExpressionParams(array $params): void
    {
        if ($params === []) {
            return;
        }

        $this->selectSubqueryParams = array_merge($this->selectSubqueryParams, $params);
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
        $built = $this->compile();

        return $this->connection->fetchAll($built->sql, $built->bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchOne(): ?array
    {
        $built = $this->compile();

        return $this->connection->fetchOne($built->sql, $built->bindings);
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

    /** @internal @return list<string|Expression> */
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

    /** @internal */
    public function lockClause(): ?string
    {
        return $this->lockClause;
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

    private function assertSimpleSelectString(string $column): void
    {
        if ($this->isComplexSelectString($column)) {
            throw new InvalidArgumentException(
                'Complex SQL is not allowed in select(). Use selectRaw() or new Expression(...).',
            );
        }
    }

    private function isComplexSelectString(string $column): bool
    {
        if (str_contains($column, '(') || str_contains($column, ')') || str_contains($column, ',')) {
            return true;
        }

        if (preg_match('/\b(AND|OR|SELECT|EXISTS|CASE|WHEN|THEN|ELSE|END|UNION|INTERSECT|EXCEPT)\b/i', $column) === 1) {
            return true;
        }

        return !$this->isSimpleSelectFragment($column);
    }

    private function isSimpleSelectFragment(string $column): bool
    {
        $column = trim($column);
        if ($column === '') {
            return false;
        }

        if ($this->isSimpleSelectReference($column)) {
            return true;
        }

        if (preg_match('/^(.+?)\s+AS\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $column, $matches) !== 1) {
            return false;
        }

        $left  = trim((string) ($matches[1] ?? ''));
        $alias = trim((string) ($matches[2] ?? ''));

        return $this->isSimpleSelectReference($left) && $this->isSimpleAlias($alias);
    }

    private function isSimpleSelectReference(string $value): bool
    {
        if ($value === '*') {
            return true;
        }

        if (preg_match('/^\d+(?:\.\d+)?$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $value) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*\.\*$/', $value) === 1;
    }

    private function isSimpleAlias(string $alias): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) === 1;
    }

    private function toggleLockSuffix(string $suffix, bool $enabled): self
    {
        if ($enabled && $this->lockClause === null) {
            $this->lockClause = 'FOR UPDATE';
        }

        if ($this->lockClause === null) {
            return $this;
        }

        $clause = $this->lockClause;
        if ($enabled) {
            if ($suffix === 'NOWAIT') {
                $clause = $this->removeWaitClause($clause);
                $clause = $this->removeLockOption($clause, 'SKIP LOCKED');
            }

            if ($suffix === 'SKIP LOCKED') {
                $clause = $this->removeWaitClause($clause);
                $clause = $this->removeLockOption($clause, 'NOWAIT');
            }

            if (preg_match('/\b' . preg_quote($suffix, '/') . '\b/i', $clause) !== 1) {
                $clause = trim($clause . ' ' . $suffix);
            }
        } else {
            $clause = $this->removeLockOption($clause, $suffix);
        }

        $this->lockClause = trim($clause) !== '' ? trim($clause) : null;

        return $this;
    }

    private function removeLockOption(string $clause, string $suffix): string
    {
        $pattern = '/\s*\b' . preg_quote($suffix, '/') . '\b/i';
        $result  = preg_replace($pattern, '', $clause);

        return $result !== null ? trim($result) : trim($clause);
    }

    private function removeWaitClause(string $clause): string
    {
        $result = preg_replace('/\s+WAIT\s+\d+\b/i', '', $clause);

        return $result !== null ? trim($result) : trim($clause);
    }

    private function isUnsafeLockClause(string $lockClause): bool
    {
        if (str_contains($lockClause, ';')) {
            return true;
        }

        if (str_contains($lockClause, '--')) {
            return true;
        }

        if (str_contains($lockClause, '/*') || str_contains($lockClause, '*/')) {
            return true;
        }

        return false;
    }
}
