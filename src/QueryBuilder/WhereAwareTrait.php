<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use InvalidArgumentException;

use function array_is_list;
use function array_key_exists;
use function array_key_last;
use function array_pop;
use function implode;
use function is_array;
use function is_callable;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_starts_with;
use function strtoupper;
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
     * @param string|array<int|string, mixed>|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function where(string|array|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            /** @var callable(self):void $sql */
            return $this->whereGroup('AND', $sql);
        }

        if (is_array($sql)) {
            return $this->whereArrayInternal('AND', $sql, $params);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->assertSimpleWhereString($sql);

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
     * @param string|array<int|string, mixed>|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function orWhere(string|array|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            /** @var callable(self):void $sql */
            return $this->whereGroup('OR', $sql);
        }

        if (is_array($sql)) {
            return $this->whereArrayInternal('OR', $sql, $params);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->assertSimpleWhereString($sql);

        $this->addWhereNode([
            'boolean' => 'OR',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * Явно сырой WHERE-фрагмент (raw SQL).
     *
     * Используйте для сложных выражений/функций, которые не покрываются структурным DSL.
     *
     * @param array<string|int, mixed> $params
     */
    public function whereRaw(string|Expression $sql, array $params = []): static
    {
        $value = trim((string) $sql);
        if ($value === '') {
            return $this;
        }

        $this->addWhereNode([
            'boolean' => 'AND',
            'type'    => 'condition',
            'sql'     => $value,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * OR-версия для raw WHERE-фрагмента.
     *
     * @param array<string|int, mixed> $params
     */
    public function orWhereRaw(string|Expression $sql, array $params = []): static
    {
        $value = trim((string) $sql);
        if ($value === '') {
            return $this;
        }

        $this->addWhereNode([
            'boolean' => 'OR',
            'type'    => 'condition',
            'sql'     => $value,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * @param array<int|string, mixed> $conditions
     * @param array<string|int, mixed> $params
     */
    private function whereArrayInternal(string $boolean, array $conditions, array $params = []): static
    {
        foreach ($conditions as $key => $condition) {
            if (is_string($key)) {
                $column = trim($key);
                if ($column === '') {
                    continue;
                }

                // shorthand: ['u.id' => [1,2,3]] => IN (...)
                if (is_array($condition) && array_is_list($condition)) {
                    $this->whereInInternal($boolean, $column, $condition, not: false);
                    continue;
                }

                if (is_array($condition) && !array_is_list($condition)) {
                    $operator = isset($condition['operator']) ? (string) $condition['operator'] : '=';
                    if (isset($condition['target_column'])) {
                        $this->whereArrayCondition($boolean, $column, $operator, ['column' => $condition['target_column']], $params);
                        continue;
                    }
                    if (array_key_exists('placeholder', $condition)) {
                        $this->whereArrayCondition($boolean, $column, $operator, (string) $condition['placeholder'], $params);
                        continue;
                    }
                    if (array_key_exists('value', $condition)) {
                        $this->whereArrayCondition($boolean, $column, $operator, $condition['value'], $params);
                        continue;
                    }

                    throw new InvalidArgumentException('Invalid where() array condition for key "' . $column . '".');
                }

                $this->whereArrayCondition($boolean, $column, '=', $condition, $params);
                continue;
            }

            if (!is_array($condition)) {
                throw new InvalidArgumentException('Invalid where() array condition item. Expected array.');
            }

            if (!array_is_list($condition)) {
                $column = trim((string) ($condition['column'] ?? ''));
                if ($column === '') {
                    throw new InvalidArgumentException('where() condition must contain non-empty "column".');
                }

                $operator = isset($condition['operator']) ? (string) $condition['operator'] : '=';
                if (isset($condition['target_column'])) {
                    $this->whereArrayCondition($boolean, $column, $operator, ['column' => $condition['target_column']], $params);
                    continue;
                }
                if (array_key_exists('placeholder', $condition)) {
                    $this->whereArrayCondition($boolean, $column, $operator, (string) $condition['placeholder'], $params);
                    continue;
                }
                if (array_key_exists('value', $condition)) {
                    $this->whereArrayCondition($boolean, $column, $operator, $condition['value'], $params);
                    continue;
                }

                throw new InvalidArgumentException('where() associative condition must contain one of: value|placeholder|target_column.');
            }

            if (($condition[0] ?? null) === null || ($condition[1] ?? null) === null || !array_key_exists(2, $condition)) {
                throw new InvalidArgumentException('where() list condition must have format [column, operator, value].');
            }

            $column   = trim((string) $condition[0]);
            $operator = (string) $condition[1];
            $operand  = $condition[2];

            $this->whereArrayCondition($boolean, $column, $operator, $operand, $params);
        }

        return $this;
    }

    /**
     * @param array<string|int, mixed> $params
     */
    private function whereArrayCondition(string $boolean, string $column, string $operator, mixed $operand, array $params): void
    {
        $column = trim($column);
        if ($column === '') {
            return;
        }

        $operator = strtoupper(trim((string) preg_replace('/\s+/', ' ', $operator)));
        if ($operator === '') {
            $operator = '=';
        }

        if ($operator === 'IN' || $operator === 'NOT IN') {
            if (!is_array($operand)) {
                throw new InvalidArgumentException('Operator ' . $operator . ' requires array operand.');
            }

            $this->whereInInternal($boolean, $column, $operand, not: $operator === 'NOT IN');

            return;
        }

        if ($operator === 'IS' || $operator === 'IS NOT') {
            if (is_array($operand) && isset($operand['column'])) {
                $targetColumn = trim((string) $operand['column']);
                if ($targetColumn === '') {
                    throw new InvalidArgumentException('target_column must be non-empty.');
                }

                $this->addWhereNode([
                    'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                    'type'    => 'condition',
                    'sql'     => $column . ' ' . $operator . ' ' . $targetColumn,
                    'params'  => [],
                ]);

                return;
            }

            if ($operand === null) {
                $this->addWhereNode([
                    'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                    'type'    => 'condition',
                    'sql'     => $column . ' ' . $operator . ' NULL',
                    'params'  => [],
                ]);

                return;
            }
        }

        if ($operand === null) {
            if ($operator === '=') {
                $this->addWhereNode([
                    'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                    'type'    => 'condition',
                    'sql'     => $column . ' IS NULL',
                    'params'  => [],
                ]);

                return;
            }

            if ($operator === '!=' || $operator === '<>') {
                $this->addWhereNode([
                    'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                    'type'    => 'condition',
                    'sql'     => $column . ' IS NOT NULL',
                    'params'  => [],
                ]);

                return;
            }
        }

        if (is_array($operand) && isset($operand['column'])) {
            $targetColumn = trim((string) $operand['column']);
            if ($targetColumn === '') {
                throw new InvalidArgumentException('target_column must be non-empty.');
            }

            $this->addWhereNode([
                'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                'type'    => 'condition',
                'sql'     => $column . ' ' . $operator . ' ' . $targetColumn,
                'params'  => [],
            ]);

            return;
        }

        if (is_string($operand) && str_starts_with(trim($operand), ':')) {
            $placeholder = trim($operand);
            $paramName   = substr($placeholder, 1);
            if ($paramName === '' || !array_key_exists($paramName, $params)) {
                throw new InvalidArgumentException(
                    'where() placeholder "' . $placeholder . '" is not present in params.',
                );
            }

            $this->addWhereNode([
                'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
                'type'    => 'condition',
                'sql'     => $column . ' ' . $operator . ' ' . $placeholder,
                'params'  => [$paramName => $params[$paramName]],
            ]);

            return;
        }

        $this->paramCounter++;
        $placeholder = ':where_' . $this->paramCounter;
        $paramName   = substr($placeholder, 1);

        $this->addWhereNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'condition',
            'sql'     => $column . ' ' . $operator . ' ' . $placeholder,
            'params'  => [$paramName => $operand],
        ]);
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

    private function assertSimpleWhereString(string $sql): void
    {
        if ($this->isComplexWhereString($sql)) {
            throw new InvalidArgumentException(
                'Complex SQL is not allowed in where()/orWhere(). Use whereRaw()/orWhereRaw() or structured where*() methods.',
            );
        }
    }

    private function isComplexWhereString(string $sql): bool
    {
        if (str_contains($sql, '(') || str_contains($sql, ')')) {
            return true;
        }

        if (preg_match('/\b(AND|OR|SELECT|EXISTS|CASE|WHEN|THEN|ELSE|END|UNION|INTERSECT|EXCEPT)\b/i', $sql) === 1) {
            return true;
        }

        return false;
    }
}
