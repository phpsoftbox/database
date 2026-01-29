<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use function implode;
use function is_array;
use function trim;

/**
 * Компилирует дерево условий (WHERE/HAVING), собранное WhereAwareTrait/HavingAwareTrait.
 */
final class ConditionTreeCompiler
{
    public function __construct(
        private readonly ConditionQuoter $conditionQuoter,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function compile(array $nodes): array
    {
        $params   = [];
        $sqlParts = [];

        foreach ($nodes as $i => $node) {
            if (!is_array($node) || !isset($node['boolean'], $node['type'])) {
                continue;
            }

            $prefix = $i === 0 ? '' : ' ' . ($node['boolean'] === 'OR' ? 'OR' : 'AND') . ' ';

            if ($node['type'] === 'condition') {
                $nodeSql = trim((string) ($node['sql'] ?? ''));
                if ($nodeSql === '') {
                    continue;
                }

                $nodeSql = $this->conditionQuoter->quote($nodeSql);

                $sqlParts[] = $prefix . '(' . $nodeSql . ')';

                foreach ((array) ($node['params'] ?? []) as $k => $v) {
                    $params[$k] = $v;
                }

                continue;
            }

            if ($node['type'] === 'group') {
                $groupCompiled = $this->compile((array) ($node['nodes'] ?? []));
                if ($groupCompiled['sql'] === '') {
                    continue;
                }

                $sqlParts[] = $prefix . '(' . $groupCompiled['sql'] . ')';

                foreach ($groupCompiled['params'] as $k => $v) {
                    $params[$k] = $v;
                }
            }
        }

        return [
            'sql'    => implode('', $sqlParts),
            'params' => $params,
        ];
    }
}
