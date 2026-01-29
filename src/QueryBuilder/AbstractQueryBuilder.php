<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PDO;
use PhpSoftBox\Database\Contracts\ConnectionInterface;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function is_numeric;
use function preg_match;
use function preg_split;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strripos;
use function substr;
use function trim;

abstract class AbstractQueryBuilder
{
    public function __construct(
        protected readonly ConnectionInterface $connection,
    ) {
    }

    protected function applyTablePrefix(string $table): string
    {
        return $this->connection->table(trim($table));
    }

    /**
     * Возвращает символ экранирования идентификаторов для текущего драйвера.
     */
    protected function identQuoteChar(): string
    {
        $driver = (string) $this->connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql', 'mariadb' => '`',
            default => '"', // pgsql, sqlite
        };
    }

    /**
     * Экранирует одиночный идентификатор (без точки).
     */
    protected function quoteIdent(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }

        $q = $this->identQuoteChar();

        // Если идентификатор уже экранирован ("..." или `...`) — не трогаем.
        if ((str_starts_with($ident, '`') && str_ends_with($ident, '`'))
            || (str_starts_with($ident, '"') && str_ends_with($ident, '"'))
        ) {
            return $ident;
        }

        if ($q === '`') {
            $ident = str_replace('`', '``', $ident);
        } else {
            $ident = str_replace('"', '""', $ident);
        }

        return $q . $ident . $q;
    }

    /**
     * Экранирует идентификатор колонки/таблицы с поддержкой dot-нотации: t.col
     */
    protected function quoteDottedIdent(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '' || $ident === '*') {
            return $ident;
        }

        $parts = array_map('trim', explode('.', $ident));
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if ($parts === []) {
            return '';
        }

        $quoted = [];
        foreach ($parts as $p) {
            if ($p === '*') {
                $quoted[] = '*';
                continue;
            }
            $quoted[] = $this->quoteIdent($p);
        }

        return implode('.', $quoted);
    }

    /**
     * Экранирует выражение вида "col AS alias" (поддерживает разные регистры AS).
     */
    protected function quoteAsExpression(string $expr): string
    {
        $expr = trim($expr);
        if ($expr === '') {
            return '';
        }

        // 1) Явные raw/expressions, функции, скобки и т.п. — не трогаем.
        // Это покрывает COUNT(*), SUM(col), EXISTS(...), CASE WHEN ..., JSON_EXTRACT(...) и т.д.
        // В этом случае ответственность экранирования внутри выражения остаётся на авторе.
        if (preg_match('/[()]/', $expr)) {
            return $expr;
        }

        // 2) Числовые литералы
        if (is_numeric($expr)) {
            return $expr;
        }

        // 3) '*' как отдельная колонка
        if ($expr === '*') {
            return $expr;
        }

        // 4) col AS alias
        $pos = stripos($expr, ' as ');
        if ($pos !== false) {
            $pos   = strripos($expr, ' as ');
            $left  = trim(substr($expr, 0, $pos));
            $right = trim(substr($expr, $pos + 4));

            if ($left !== '' && $right !== '') {
                // Левую часть экранируем только если она простая: ident или ident.ident
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $left)) {
                    $left = $this->quoteDottedIdent($left);
                }

                return $left . ' AS ' . $this->quoteIdent($right);
            }
        }

        // 5) Простые идентификаторы: ident или ident.ident или ident.*
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*|\.[*])?$/', $expr)) {
            return $this->quoteDottedIdent($expr);
        }

        // 6) Фоллбек: сложное выражение — не трогаем.
        return $expr;
    }

    /**
     * Экранирует имя таблицы, поддерживая алиас: "users u" / "schema.users u".
     */
    protected function quoteTableWithOptionalAlias(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        // Разделяем по пробелам, но ожидаем максимум 2 части: table + alias.
        $parts = preg_split('/\s+/', $table) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        if ($parts === []) {
            return '';
        }

        $name  = $parts[0];
        $alias = $parts[1] ?? null;

        $out = $this->quoteDottedIdent($name);
        if ($alias !== null) {
            $out .= ' ' . $this->quoteIdent($alias);
        }

        return $out;
    }
}
