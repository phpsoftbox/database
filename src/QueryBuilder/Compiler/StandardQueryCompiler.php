<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\DeleteQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\InsertQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\UpdateQueryBuilder;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function implode;
use function preg_match;
use function str_contains;
use function str_starts_with;
use function substr;
use function trim;

final class StandardQueryCompiler extends AbstractQueryCompiler implements QueryCompilerInterface
{
    public function compileSelect(SelectQueryBuilder $builder): array
    {
        $unions  = $builder->unions();
        $orderBy = $builder->orderByClauses();
        $limit   = $builder->limitValue();
        $offset  = $builder->offsetValue();

        // Если есть UNION и при этом ORDER/LIMIT/OFFSET, оборачиваем запрос.
        if ($unions !== [] && ($orderBy !== [] || $limit !== null || $offset !== null)) {
            return $this->compileSelectUnionWrapped($builder);
        }

        $columnsSql = [];
        foreach ($builder->columns() as $c) {
            $c = $this->quoteSelectColumn($c);
            if ($c !== '') {
                $columnsSql[] = $c;
            }
        }
        if ($columnsSql === []) {
            $columnsSql = ['*'];
        }

        $sql = 'SELECT ' . ($builder->isDistinct() ? 'DISTINCT ' : '') . implode(', ', $columnsSql);

        $from = $builder->fromValue();
        if ($from !== null && $from !== '') {
            $sql .= ' FROM ' . ($builder->fromIsRaw() ? $from : $this->quoteTableWithOptionalAlias($from));
        }

        $params = [];
        $params = array_merge($params, $builder->fromSubqueryParams());

        $condQuoter = new ConditionQuoter($this->quoter);

        foreach ($builder->joins() as $j) {
            $sql .= ' ' . $j['type'] . ' JOIN ' . $this->joinTableToSql($j['table']) . ' ON ' . $condQuoter->quote($j['on']);
            if (($j['params'] ?? []) !== []) {
                $params = array_merge($params, (array) $j['params']);
            }
        }

        $whereCompiled = new ConditionTreeCompiler($condQuoter)->compile($builder->whereNodes());

        if ($whereCompiled['sql'] !== '') {
            $sql .= ' WHERE ' . $whereCompiled['sql'];
            $params = array_merge($params, $whereCompiled['params']);
        }

        $groupBy = $builder->groupByColumns();
        if ($groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn (string $c): string => $this->quoter->dotted($c), $groupBy));
        }

        $havingCompiled = new ConditionTreeCompiler($condQuoter)->compile($builder->havingNodes());

        if ($havingCompiled['sql'] !== '') {
            $sql .= ' HAVING ' . $havingCompiled['sql'];
            $params = array_merge($params, $havingCompiled['params']);
        }

        if ($orderBy !== []) {
            $parts = [];
            foreach ($orderBy as $o) {
                $parts[] = $this->quoteOrderByExpr($o['column']) . ' ' . $o['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }

        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        foreach ($unions as $u) {
            $sql .= ' ' . $u['type'] . ' (' . $u['query'] . ')';
            if ($u['params'] !== []) {
                $params = array_merge($params, $u['params']);
            }
        }

        $params = array_merge($params, $builder->selectSubqueryParams());

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * UNION wrapping: SELECT * FROM (<union query without order/limit/offset>) AS _u ...
     */
    private function compileSelectUnionWrapped(SelectQueryBuilder $builder): array
    {
        $base = $this->compileSelect($builder->resetPaginationAndOrderForUnion());

        $sql    = 'SELECT * FROM (' . $base['sql'] . ') AS _u';
        $params = $base['params'];

        $orderBy = $builder->orderByClauses();
        if ($orderBy !== []) {
            $parts = [];
            foreach ($orderBy as $o) {
                $parts[] = $this->quoteOrderByExpr($o['column']) . ' ' . $o['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($builder->limitValue() !== null) {
            $sql .= ' LIMIT ' . $builder->limitValue();
        }

        if ($builder->offsetValue() !== null) {
            $sql .= ' OFFSET ' . $builder->offsetValue();
        }

        return ['sql' => $sql, 'params' => $params];
    }

    public function compileInsert(InsertQueryBuilder $builder): array
    {
        $data = $builder->data();
        $cols = array_keys($data);
        $cols = array_values(array_filter(array_map('trim', $cols), static fn (string $c): bool => $c !== ''));

        $sql = 'INSERT INTO ' . $this->quoteTableWithOptionalAlias($builder->table());
        if ($cols === []) {
            $sql .= ' DEFAULT VALUES';

            return ['sql' => $sql, 'params' => []];
        }

        $params       = [];
        $placeholders = [];
        $quotedCols   = [];

        $i = 0;
        foreach ($cols as $col) {
            $i++;
            $p                     = ':v_' . $i;
            $placeholders[]        = $p;
            $params[substr($p, 1)] = $data[$col];
            $quotedCols[]          = $this->quoter->ident($col);
        }

        $sql .= ' (' . implode(', ', $quotedCols) . ') VALUES (' . implode(', ', $placeholders) . ')';

        return ['sql' => $sql, 'params' => $params];
    }

    public function compileUpdate(UpdateQueryBuilder $builder): array
    {
        $data = $builder->data();
        $cols = array_keys($data);
        $cols = array_values(array_filter(array_map('trim', $cols), static fn (string $c): bool => $c !== ''));

        $sql = 'UPDATE ' . $this->quoteTableWithOptionalAlias($builder->table()) . ' SET ';

        $params   = [];
        $setParts = [];

        $i = 0;
        foreach ($cols as $col) {
            $i++;
            $p = ':v_' . $i;

            $setParts[]            = $this->quoter->ident($col) . ' = ' . $p;
            $params[substr($p, 1)] = $data[$col];
        }

        if ($setParts === []) {
            $setParts[] = '1 = 1';
        }

        $sql .= implode(', ', $setParts);

        $condQuoter = new ConditionQuoter($this->quoter);

        $whereCompiled = new ConditionTreeCompiler($condQuoter)->compile($builder->whereNodes());

        if ($whereCompiled['sql'] !== '') {
            $sql .= ' WHERE ' . $whereCompiled['sql'];
            $params = array_merge($params, $whereCompiled['params']);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    public function compileDelete(DeleteQueryBuilder $builder): array
    {
        $sql = 'DELETE FROM ' . $this->quoteTableWithOptionalAlias($builder->table());

        $condQuoter = new ConditionQuoter($this->quoter);

        $whereCompiled = new ConditionTreeCompiler($condQuoter)->compile($builder->whereNodes());

        $params = [];
        if ($whereCompiled['sql'] !== '') {
            $sql .= ' WHERE ' . $whereCompiled['sql'];
            $params = $whereCompiled['params'];
        }

        return ['sql' => $sql, 'params' => $params];
    }

    private function joinTableToSql(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        if (str_starts_with($table, '(')) {
            return $table;
        }

        if (str_contains($table, '(') || str_contains($table, ')')) {
            return $table;
        }

        // Raw выражения JOIN (обычно из Expression): "clients c".
        if (preg_match('/\s+/', $table) === 1 && !str_contains($table, '.') && !str_contains($table, '"') && !str_contains($table, '`')) {
            return $table;
        }

        return $this->quoteTableWithOptionalAlias($table);
    }
}
