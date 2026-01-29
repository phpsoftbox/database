<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function in_array;
use function is_array;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtoupper;
use function trim;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Очень лёгкий "квотер" для WHERE/HAVING/ON.
 *
 * Важно: эти условия хранятся как raw SQL. Мы не можем надёжно распарсить любые выражения,
 * поэтому делаем безопасную эвристику:
 *  - квотим только простые идентификаторы: col или t.col
 *  - НЕ квотим токены, если рядом есть '(' или это число
 *  - не трогаем плейсхолдеры :name
 *
 * Это не парсер SQL и не пытается быть им.
 */
final class ConditionQuoter
{
    public function __construct(
        private readonly QuoterInterface $quoter,
    ) {
    }

    public function quote(string $sql): string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return '';
        }

        // Если внутри условия явно есть подзапрос — не пытаемся его "умно" квотить.
        // Подзапрос уже собран QueryBuilder'ом и будет корректным.
        if (preg_match('/\bSELECT\b/i', $sql) === 1) {
            return $sql;
        }

        // Режем по токенам, сохраняя разделители.
        $parts = preg_split('/(\s+)/u', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $sql;
        }

        $out = '';

        foreach ($parts as $part) {
            // пробелы
            if ($part === '' || preg_match('/^\s+$/u', $part) === 1) {
                $out .= $part;
                continue;
            }

            // Плейсхолдеры или токены, содержащие плейсхолдеры (:id, :in_1, :between_1)
            if (str_contains($part, ':')) {
                $out .= $part;
                continue;
            }

            // Числа
            if (preg_match('/^\d+(?:\.\d+)?$/', $part) === 1) {
                $out .= $part;
                continue;
            }

            // Уже квочено
            if ((str_starts_with($part, '`') && str_ends_with($part, '`'))
                || (str_starts_with($part, '"') && str_ends_with($part, '"'))
            ) {
                $out .= $part;
                continue;
            }

            // Функции (COUNT, SUM, ...): COUNT(*) / SUM(col) — само имя функции не квочим.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(.*$/', $part) === 1) {
                $out .= $part;
                continue;
            }

            // Простые идентификаторы: a или a.b
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $part) === 1) {
                $upper = strtoupper($part);
                if (in_array($upper, [
                    'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'EXISTS', 'LIKE', 'BETWEEN', 'ON',
                    'TRUE', 'FALSE', 'AS',
                ], true)) {
                    $out .= $part;
                    continue;
                }

                $out .= $this->quoter->dotted($part);
                continue;
            }

            $out .= $part;
        }

        return $out;
    }
}
