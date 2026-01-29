<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use function array_key_last;
use function array_pop;
use function implode;
use function is_callable;
use function substr;
use function trim;

trait WhereAwareTrait
{
    /**
     * Узлы WHERE верхнего уровня.
     *
     * @var list<array{boolean: 'AND'|'OR', type: 'condition', sql: string, params: array<string|int, mixed>}|array{boolean: 'AND'|'OR', type: 'group', nodes: array}>
     */
    private array $where = [];

    /**
     * Стек активных групп (id буфера). Пустой стек => пишем в $where.
     *
     * @var list<int>
     */
    private array $whereGroupStack = [];

    /**
     * Буфер узлов для группировок.
     *
     * @var array<int, array>
     */
    private array $whereGroupBuffers = [];

    private int $whereGroupAutoIncrement = 0;

    /**
     * Добавляет условие WHERE.
     *
     * Можно передать либо SQL-строку, либо callback для группировки условий в скобки.
     *
     * Пример:
     *
     * ```
     * $conn->query()->select()->from('users')
     *     ->where('active = 1')
     *     ->where(function (SelectQueryBuilder $q): void {
     *         $q->where('age > :age', ['age' => 30])
     *           ->orWhere('phone = :phone', ['phone' => '+11111111111']);
     *     });
     * ```
     *
     * @template T of SelectQueryBuilder|UpdateQueryBuilder|DeleteQueryBuilder
     *
     * @param string|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function where(string|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            /** @var callable(self):void $sql */
            return $this->whereGroup('AND', $sql);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->addWhereNode([
            'boolean' => 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * Добавляет условие WHERE с логическим OR.
     *
     * Также поддерживает callback для группировки условий.
     *
     * @template T of SelectQueryBuilder|UpdateQueryBuilder|DeleteQueryBuilder
     *
     * @param string|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function orWhere(string|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            /** @var callable(self):void $sql */
            return $this->whereGroup('OR', $sql);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->addWhereNode([
            'boolean' => 'OR',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * Возвращает текущий id буфера группы или null если активен корень.
     */
    private function currentWhereGroupId(): ?int
    {
        if ($this->whereGroupStack === []) {
            return null;
        }

        return $this->whereGroupStack[array_key_last($this->whereGroupStack)];
    }

    /**
     * Добавляет узел в текущий активный target (корень или активная группа).
     */
    private function addWhereNode(array $node): void
    {
        $groupId = $this->currentWhereGroupId();
        if ($groupId === null) {
            $this->where[] = $node;

            return;
        }

        $this->whereGroupBuffers[$groupId][] = $node;
    }

    /**
     * Группирует условия в скобки.
     *
     * @param callable(self):void $callback
     */
    private function whereGroup(string $boolean, callable $callback): static
    {
        $groupId                           = ++$this->whereGroupAutoIncrement;
        $this->whereGroupBuffers[$groupId] = [];

        $this->whereGroupStack[] = $groupId;
        try {
            $callback($this);
        } finally {
            array_pop($this->whereGroupStack);
        }

        $nodes = $this->whereGroupBuffers[$groupId] ?? [];
        unset($this->whereGroupBuffers[$groupId]);

        if ($nodes === []) {
            return $this;
        }

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'group',
            'nodes'   => $nodes,
        ]);

        return $this;
    }

    public function whereNull(string $column): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        return $this->where($column . ' IS NULL');
    }

    public function whereNotNull(string $column): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        return $this->where($column . ' IS NOT NULL');
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        return $this->whereInInternal('AND', $column, $values);
    }

    /**
     * @param list<mixed> $values
     */
    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereInInternal('OR', $column, $values);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): static
    {
        return $this->whereInInternal('AND', $column, $values, not: true);
    }

    /**
     * @param list<mixed> $values
     */
    public function orWhereNotIn(string $column, array $values): static
    {
        return $this->whereInInternal('OR', $column, $values, not: true);
    }

    /**
     * @param list<mixed> $values
     */
    private function whereInInternal(string $boolean, string $column, array $values, bool $not = false): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        if ($values === []) {
            // Пустой IN всегда false, а пустой NOT IN всегда true.
            $sql    = $not ? '1 = 1' : '1 = 0';
            $params = [];
        } else {
            $placeholders = [];
            $params       = [];

            foreach ($values as $value) {
                $this->paramCounter++;
                $name                     = ':in_' . $this->paramCounter;
                $placeholders[]           = $name;
                $params[substr($name, 1)] = $value;
            }

            $sql = $column . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $placeholders) . ')';
        }

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * Возвращает AST узлы WHERE.
     *
     * @internal
     * @return list<array<string, mixed>>
     */
    public function whereNodes(): array
    {
        return $this->where;
    }

    public function whereLike(string $column, string $pattern): static
    {
        return $this->whereLikeInternal('AND', $column, $pattern, not: false);
    }

    public function orWhereLike(string $column, string $pattern): static
    {
        return $this->whereLikeInternal('OR', $column, $pattern, not: false);
    }

    public function whereNotLike(string $column, string $pattern): static
    {
        return $this->whereLikeInternal('AND', $column, $pattern, not: true);
    }

    public function orWhereNotLike(string $column, string $pattern): static
    {
        return $this->whereLikeInternal('OR', $column, $pattern, not: true);
    }

    private function whereLikeInternal(string $boolean, string $column, string $pattern, bool $not): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        $this->paramCounter++;
        $p = ':like_' . $this->paramCounter;

        $sql = $column . ' ' . ($not ? 'NOT LIKE' : 'LIKE') . ' ' . $p;

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => [substr($p, 1) => $pattern],
        ]);

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): static
    {
        return $this->whereBetweenInternal('AND', $column, $from, $to, not: false);
    }

    public function orWhereBetween(string $column, mixed $from, mixed $to): static
    {
        return $this->whereBetweenInternal('OR', $column, $from, $to, not: false);
    }

    public function whereNotBetween(string $column, mixed $from, mixed $to): static
    {
        return $this->whereBetweenInternal('AND', $column, $from, $to, not: true);
    }

    public function orWhereNotBetween(string $column, mixed $from, mixed $to): static
    {
        return $this->whereBetweenInternal('OR', $column, $from, $to, not: true);
    }

    private function whereBetweenInternal(string $boolean, string $column, mixed $from, mixed $to, bool $not): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        $this->paramCounter++;
        $p1 = ':between_' . $this->paramCounter;
        $this->paramCounter++;
        $p2 = ':between_' . $this->paramCounter;

        $sql = $column . ' ' . ($not ? 'NOT BETWEEN' : 'BETWEEN') . ' ' . $p1 . ' AND ' . $p2;

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => [
                substr($p1, 1) => $from,
                substr($p2, 1) => $to,
            ],
        ]);

        return $this;
    }

    /**
     * WHERE col IN (<subquery>). Подзапрос можно собрать через callback, передать готовый SelectQueryBuilder,
     * или raw SQL (string/Expression).
     *
     * Пример:
     *
     * ```
     * $conn->query()->select()->from('users')
     *     ->whereInSubquery('id', function (SelectQueryBuilder $q): void {
     *         $q->select('user_id')->from('orders')->where('status = :st', ['st' => 'paid']);
     *     });
     * ```
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function whereInSubquery(string $column, callable|SelectQueryBuilder|Expression|string $subquery): static
    {
        return $this->whereInSubqueryInternal('AND', $column, $subquery, not: false);
    }

    /**
     * OR WHERE col IN (<subquery>)
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function orWhereInSubquery(string $column, callable|SelectQueryBuilder|Expression|string $subquery): static
    {
        return $this->whereInSubqueryInternal('OR', $column, $subquery, not: false);
    }

    /**
     * WHERE col NOT IN (<subquery>)
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function whereNotInSubquery(string $column, callable|SelectQueryBuilder|Expression|string $subquery): static
    {
        return $this->whereInSubqueryInternal('AND', $column, $subquery, not: true);
    }

    /**
     * OR WHERE col NOT IN (<subquery>)
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    public function orWhereNotInSubquery(string $column, callable|SelectQueryBuilder|Expression|string $subquery): static
    {
        return $this->whereInSubqueryInternal('OR', $column, $subquery, not: true);
    }

    /**
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     */
    private function whereInSubqueryInternal(string $boolean, string $column, callable|SelectQueryBuilder|Expression|string $subquery, bool $not): static
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }

        $compiled = $this->compileWhereSubquery($subquery);
        $subSql   = trim($compiled['sql']);
        if ($subSql === '') {
            return $this;
        }

        $sql = $column . ($not ? ' NOT IN (' : ' IN (') . $subSql . ')';

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $compiled['params'],
        ]);

        return $this;
    }

    /**
     * Компилирует подзапрос для WHERE-условий.
     *
     * @param callable(SelectQueryBuilder):void|SelectQueryBuilder|Expression|string $subquery
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function compileWhereSubquery(callable|SelectQueryBuilder|Expression|string $subquery): array
    {
        if ($subquery instanceof SelectQueryBuilder) {
            return $subquery->toSql();
        }

        if ($subquery instanceof Expression) {
            return ['sql' => trim((string) $subquery), 'params' => []];
        }

        if (is_callable($subquery)) {
            /** @var callable(SelectQueryBuilder):void $subquery */

            // WhereAwareTrait предполагает использование в билдере, который наследуется от AbstractQueryBuilder.
            $q = new SelectQueryBuilder($this->connection, ['*']);

            $subquery($q);

            return $q->toSql();
        }

        return ['sql' => trim((string) $subquery), 'params' => []];
    }
}
