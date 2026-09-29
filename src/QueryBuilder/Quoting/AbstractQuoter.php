<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Quoting;

use InvalidArgumentException;

use function array_slice;
use function array_values;
use function count;
use function implode;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_split;
use function sprintf;
use function str_replace;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Базовое экранирование идентификаторов.
 *
 * Идентификатор (ident/dotted) принимается только в двух формах:
 *  - простое имя: буквы, цифры и подчёркивание (users, created_at, 2fa_codes);
 *  - уже экранированное имя в кавычках текущего диалекта с корректным экранированием
 *    внутренних кавычек ("my ""name""" для ANSI, `my ``name``` для MySQL).
 *
 * Остальные строки (пробелы, операторы, запятые, кавычки чужого диалекта и т.п.) отклоняются
 * InvalidArgumentException: имена колонок могут приходить из данных запроса (mass-assignment, сортировка),
 * и строку вида '"is_admin" = true, "name"' нельзя считать «уже экранированной».
 */
abstract class AbstractQuoter implements QuoterInterface
{
    private const string BARE_PATTERN = '[\p{L}\p{N}_]+';

    abstract protected function quoteChar(): string;

    public function ident(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }

        if (preg_match('/^' . self::BARE_PATTERN . '$/u', $ident) === 1) {
            return $this->wrap($ident);
        }

        if (preg_match('/^' . $this->quotedPattern() . '$/u', $ident) === 1) {
            return $ident;
        }

        throw $this->invalidIdentifier($ident);
    }

    public function dotted(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '' || $ident === '*') {
            return $ident;
        }

        $segment = '(?:' . self::BARE_PATTERN . '|' . $this->quotedPattern() . ')';
        if (preg_match('/^' . $segment . '(?:\.' . $segment . ')*(?:\.\*)?$/u', $ident) !== 1) {
            throw $this->invalidIdentifier($ident);
        }

        preg_match_all('/' . $segment . '|\*/u', $ident, $matches);

        $out = [];
        foreach ($matches[0] as $part) {
            $out[] = $part === '*' ? '*' : $this->ident($part);
        }

        return implode('.', $out);
    }

    /**
     * Алиас экранируется всегда: допускается любая непустая строка, внутренние кавычки удваиваются.
     * Корректно экранированный алиас текущего диалекта возвращается как есть.
     */
    public function alias(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '') {
            return '';
        }

        if (preg_match('/^' . $this->quotedPattern() . '$/u', $alias) === 1) {
            return $alias;
        }

        return $this->wrap($alias);
    }

    /**
     * Экранирует имя таблицы с необязательным алиасом.
     *
     * Пример:
     *  - users
     *  - users u     => "users" AS "u"
     *  - users AS u  => "users" AS "u"
     */
    public function tableWithOptionalAlias(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            return '';
        }

        $parts = array_values(preg_split('/\s+/', $table) ?: []);
        if (isset($parts[2]) && strtoupper($parts[1]) === 'AS') {
            $parts = [$parts[0], $parts[2], ...array_slice($parts, 3)];
        }

        if (count($parts) > 2) {
            throw $this->invalidIdentifier($table);
        }

        $out = $this->dotted($parts[0]);
        if (isset($parts[1])) {
            $out .= ' AS ' . $this->ident($parts[1]);
        }

        return $out;
    }

    private function wrap(string $value): string
    {
        $q = $this->quoteChar();

        return $q . str_replace($q, $q . $q, $value) . $q;
    }

    /**
     * Регулярное выражение экранированного идентификатора текущего диалекта: "a""b" / `a``b`.
     */
    private function quotedPattern(): string
    {
        $q = preg_quote($this->quoteChar(), '/');

        return $q . '(?:[^' . $q . ']|' . $q . $q . ')+' . $q;
    }

    private function invalidIdentifier(string $ident): InvalidArgumentException
    {
        $shown = strlen($ident) > 100 ? substr($ident, 0, 100) . '...' : $ident;

        return new InvalidArgumentException(sprintf(
            'Invalid SQL identifier "%s". Use letters, digits and underscore (optionally dotted: schema.table.column) or a properly quoted identifier.',
            $shown,
        ));
    }
}
