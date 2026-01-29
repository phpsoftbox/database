<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Quoting;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;
use function preg_split;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trim;

abstract class AbstractQuoter implements QuoterInterface
{
    abstract protected function quoteChar(): string;

    public function ident(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }

        // Уже экранирован? Тогда не трогаем.
        if ((str_starts_with($ident, '`') && str_ends_with($ident, '`'))
            || (str_starts_with($ident, '"') && str_ends_with($ident, '"'))
        ) {
            return $ident;
        }

        $q = $this->quoteChar();

        $escaped = $ident;
        if ($q === '`') {
            $escaped = str_replace('`', '``', $escaped);
        } else {
            $escaped = str_replace('"', '""', $escaped);
        }

        return $q . $escaped . $q;
    }

    public function dotted(string $ident): string
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

        $out = [];
        foreach ($parts as $p) {
            if ($p === '*') {
                $out[] = '*';
                continue;
            }
            $out[] = $this->ident($p);
        }

        return implode('.', $out);
    }

    public function alias(string $alias): string
    {
        return $this->ident($alias);
    }

    /**
     * Экранирует имя таблицы с необязательным алиасом.
     *
     * Пример:
     *  - users
     *  - users u  => "users" AS "u"
     */
    public function tableWithOptionalAlias(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $table) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));

        $name  = $parts[0] ?? '';
        $alias = $parts[1] ?? null;

        $out = $this->dotted($name);
        if ($alias !== null) {
            $out .= ' AS ' . $this->alias($alias);
        }

        return $out;
    }
}
