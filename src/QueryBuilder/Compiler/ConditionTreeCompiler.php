<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use function implode;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function preg_match_all;
use function preg_replace_callback;
use function trim;

/**
 * Компилирует дерево условий (WHERE/HAVING), собранное WhereAwareTrait/HavingAwareTrait.
 */
final class ConditionTreeCompiler
{
    private int $placeholderCounter = 0;

    /**
     * Уже занятые named-параметры в рамках текущей компиляции дерева.
     *
     * @var array<string, true>
     */
    private array $usedNamedParams = [];

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

                $nodeSql   = $this->conditionQuoter->quote($nodeSql);
                $rewritten = $this->rewriteConditionPlaceholders($nodeSql, (array) ($node['params'] ?? []));

                $sqlParts[] = $prefix . '(' . $rewritten['sql'] . ')';
                foreach ($rewritten['params'] as $k => $v) {
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

    /**
     * Переименовывает repeated/colliding named-placeholder'ы в пределах compiled дерева.
     *
     * Внешний API остаётся прежним (пользователь пишет :query), но внутри мы избегаем
     * конфликтов вида ":query" в нескольких WHERE/OR WHERE фрагментах.
     *
     * @param array<string|int, mixed> $params
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    private function rewriteConditionPlaceholders(string $sql, array $params): array
    {
        if ($params === []) {
            return ['sql' => $sql, 'params' => []];
        }

        /** @var array<string, mixed> $named */
        $named = [];
        /** @var array<int, mixed> $positional */
        $positional = [];

        foreach ($params as $k => $v) {
            if (is_int($k)) {
                $positional[$k] = $v;
                continue;
            }

            $name = ltrim((string) $k, ':');
            if ($name === '') {
                continue;
            }
            $named[$name] = $v;
        }

        if ($named === []) {
            return ['sql' => $sql, 'params' => $params];
        }

        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        /** @var list<string> $placeholders */
        $placeholders = $matches[1] ?? [];

        if ($placeholders === []) {
            return ['sql' => $sql, 'params' => $params];
        }

        /** @var array<string, int> $occurrences */
        $occurrences = [];
        foreach ($placeholders as $name) {
            if (!isset($named[$name])) {
                continue;
            }
            $occurrences[$name] = ($occurrences[$name] ?? 0) + 1;
        }

        if ($occurrences === []) {
            return ['sql' => $sql, 'params' => $params];
        }

        $needsRewrite = false;
        foreach ($occurrences as $name => $count) {
            if ($count > 1 || isset($this->usedNamedParams[$name])) {
                $needsRewrite = true;
                break;
            }
        }

        if (!$needsRewrite) {
            foreach ($occurrences as $name => $_count) {
                $this->usedNamedParams[$name] = true;
            }

            return ['sql' => $sql, 'params' => $params];
        }

        /** @var array<string, mixed> $outNamed */
        $outNamed = [];
        /** @var array<string, true> $consumedNamed */
        $consumedNamed = [];

        $rewrittenSql = preg_replace_callback(
            '/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $m) use (&$outNamed, &$consumedNamed, $named, $occurrences): string {
                $name = (string) ($m[1] ?? '');
                if ($name === '' || !isset($named[$name])) {
                    return (string) ($m[0] ?? '');
                }

                $consumedNamed[$name] = true;
                $requiresUniqueName   = (($occurrences[$name] ?? 0) > 1) || isset($this->usedNamedParams[$name]);

                if (!$requiresUniqueName && !isset($this->usedNamedParams[$name])) {
                    $outNamed[$name]              = $named[$name];
                    $this->usedNamedParams[$name] = true;

                    return ':' . $name;
                }

                $this->placeholderCounter++;
                $newName = '__qb_auto_' . $this->placeholderCounter;
                while (isset($named[$newName]) || isset($this->usedNamedParams[$newName]) || isset($outNamed[$newName])) {
                    $this->placeholderCounter++;
                    $newName = '__qb_auto_' . $this->placeholderCounter;
                }

                $outNamed[$newName]              = $named[$name];
                $this->usedNamedParams[$newName] = true;

                return ':' . $newName;
            },
            $sql,
        );

        if (!is_string($rewrittenSql)) {
            return ['sql' => $sql, 'params' => $params];
        }

        foreach ($named as $name => $value) {
            if (!isset($consumedNamed[$name])) {
                if (isset($outNamed[$name])) {
                    continue;
                }
                $outNamed[$name] = $value;
            }
        }

        /** @var array<string|int, mixed> $outParams */
        $outParams = $outNamed;
        foreach ($positional as $k => $v) {
            $outParams[$k] = $v;
        }

        return ['sql' => $rewrittenSql, 'params' => $outParams];
    }
}
