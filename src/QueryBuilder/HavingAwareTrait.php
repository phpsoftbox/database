<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use function array_key_last;
use function array_pop;
use function is_callable;
use function trim;

trait HavingAwareTrait
{
    /**
     * Узлы HAVING верхнего уровня.
     *
     * @var list<array{boolean: 'AND'|'OR', type: 'condition', sql: string, params: array<string|int, mixed>}|array{boolean: 'AND'|'OR', type: 'group', nodes: array}>
     */
    private array $having = [];

    /**
     * Стек активных групп (id буфера). Пустой стек => пишем в $having.
     *
     * @var list<int>
     */
    private array $havingGroupStack = [];

    /**
     * Буфер узлов для группировок.
     *
     * @var array<int, array>
     */
    private array $havingGroupBuffers = [];

    private int $havingGroupAutoIncrement = 0;

    /**
     * Добавляет HAVING.
     *
     * Можно передать либо SQL-строку, либо callback для группировки условий в скобки.
     *
     * Пример:
     *
     * ```
     * $conn->query()->select(['client_id', 'COUNT(*) as cnt'])->from('orders')
     *     ->groupBy('client_id')
     *     ->having('cnt > :min', ['min' => 10])
     *     ->having(function (SelectQueryBuilder $q): void {
     *         $q->having('SUM(price) > :sum', ['sum' => 1000])
     *           ->orHaving('MAX(price) > :max', ['max' => 500]);
     *     });
     * ```
     *
     * @template T of SelectQueryBuilder
     * @param string|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function having(string|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            return $this->havingGroup('AND', $sql);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->addHavingNode([
            'boolean' => 'AND',
            'type'    => 'condition',
            'sql'     => $sql,
            'params'  => $params,
        ]);

        return $this;
    }

    /**
     * Добавляет HAVING с логическим OR.
     *
     * Также поддерживает callback для группировки условий.
     *
     * @template T of SelectQueryBuilder
     * @param string|callable(T):void $sql
     * @param array<string|int, mixed> $params
     */
    public function orHaving(string|callable $sql, array $params = []): static
    {
        if (is_callable($sql)) {
            return $this->havingGroup('OR', $sql);
        }

        $sql = trim($sql);
        if ($sql === '') {
            return $this;
        }

        $this->addHavingNode([
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
    private function currentHavingGroupId(): ?int
    {
        if ($this->havingGroupStack === []) {
            return null;
        }

        return $this->havingGroupStack[array_key_last($this->havingGroupStack)];
    }

    /**
     * Добавляет узел в текущий активный target (корень или активная группа).
     */
    private function addHavingNode(array $node): void
    {
        $groupId = $this->currentHavingGroupId();
        if ($groupId === null) {
            $this->having[] = $node;

            return;
        }

        $this->havingGroupBuffers[$groupId][] = $node;
    }

    /**
     * Группирует условия HAVING в скобки.
     *
     * @param callable(self):void $callback
     */
    private function havingGroup(string $boolean, callable $callback): static
    {
        $groupId                            = ++$this->havingGroupAutoIncrement;
        $this->havingGroupBuffers[$groupId] = [];

        $this->havingGroupStack[] = $groupId;
        try {
            $callback($this);
        } finally {
            array_pop($this->havingGroupStack);
        }

        $nodes = $this->havingGroupBuffers[$groupId] ?? [];
        unset($this->havingGroupBuffers[$groupId]);

        if ($nodes === []) {
            return $this;
        }

        $this->addHavingNode([
            'boolean' => $boolean === 'OR' ? 'OR' : 'AND',
            'type'    => 'group',
            'nodes'   => $nodes,
        ]);

        return $this;
    }

    /**
     * Возвращает AST узлы HAVING.
     *
     * @internal
     * @return list<array<string, mixed>>
     */
    public function havingNodes(): array
    {
        return $this->having;
    }
}
