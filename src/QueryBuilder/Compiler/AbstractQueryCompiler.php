<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\Expression;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function preg_match;
use function str_contains;
use function stripos;
use function strripos;
use function substr;
use function trim;

abstract class AbstractQueryCompiler
{
    public function __construct(
        protected readonly QuoterInterface $quoter,
    ) {
    }

    /**
     * Возвращает Quoter, используемый компилером.
     *
     * Это позволяет использовать StandardQueryCompiler как самостоятельный сервис
     * (например, для ручной сборки/проверки SQL) и упрощает тестирование.
     */
    public function quoter(): QuoterInterface
    {
        return $this->quoter;
    }

    /**
     * Экранирует имя таблицы с необязательным алиасом:
     *  - users
     *  - users u
     *  - schema.users u
     */
    protected function quoteTableWithOptionalAlias(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        return $this->quoter->tableWithOptionalAlias($table);
    }

    /**
     * Квотит колонку/выражение в SELECT.
     *
     * Правила (как договорились):
     *  - если строка содержит скобки, считаем, что это выражение/функция и не квотим её содержимое,
     *    но алиас после AS (если есть) квотим;
     *  - если скобок нет: это идентификатор (col или t.col), его квотим;
     *  - если есть AS: левую часть квотим по правилам выше, алиас квотим всегда.
     */
    public function quoteSelectColumn(string $column): string
    {
        $column = trim($column);
        if ($column === '') {
            return '';
        }

        if ($column === '*') {
            return '*';
        }

        // Числовые литералы (например: SELECT 1)
        if (preg_match('/^\d+(?:\.\d+)?$/', $column) === 1) {
            return $column;
        }

        $pos = stripos($column, ' as ');
        if ($pos !== false) {
            $pos   = strripos($column, ' as ');
            $left  = trim(substr($column, 0, $pos));
            $alias = trim(substr($column, $pos + 4));

            if ($left === '') {
                return $column;
            }

            // Если это выражение/функция (есть скобки) — НЕ трогаем левую часть.
            // Важно для EXISTS (...), COUNT(...), SUM(...), etc.
            $leftQuoted = (str_contains($left, '(') || str_contains($left, ')'))
                ? $left
                : $this->quoteSelectColumn($left);

            if ($alias !== '') {
                // Служебный алиас для агрегатов оставляем без quoting, чтобы не ломать доступ $row['__agg'].
                if ($alias === '__agg') {
                    return $leftQuoted . ' AS ' . $alias;
                }

                return $leftQuoted . ' AS ' . $this->quoter->alias($alias);
            }

            return $leftQuoted;
        }

        // Если есть скобки — считаем, что это выражение, не трогаем.
        if (str_contains($column, '(') || str_contains($column, ')')) {
            return $column;
        }

        // Простое имя колонки/квалифицированное.
        return $this->quoter->dotted($column);
    }

    /**
     * Квотит ORDER BY выражение.
     *
     * Сейчас поддерживаем самый частый кейс: имя колонки без скобок.
     * Если это выражение (есть скобки) — оставляем как есть.
     */
    protected function quoteOrderByExpr(string $expr): string
    {
        $expr = trim($expr);
        if ($expr === '') {
            return '';
        }

        if (str_contains($expr, '(') || str_contains($expr, ')')) {
            return $expr;
        }

        // `u.id` / `id`
        return $this->quoter->dotted($expr);
    }

    /**
     */
    protected function tableToSql(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return trim((string) $table);
        }

        return $this->quoteTableWithOptionalAlias($table);
    }
}
