<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\Quoting\MySqlQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function is_string;
use function ltrim;
use function preg_match;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;

/**
 * Очень лёгкий "квотер" для WHERE/HAVING/ON.
 *
 * Важно: эти условия хранятся как raw SQL. Мы не можем надёжно распарсить любые выражения,
 * поэтому делаем безопасную эвристику:
 *  - квотим только простые идентификаторы: col или t.col;
 *  - не трогаем строковые литералы ('...'), уже экранированные идентификаторы ("..." / `...`),
 *    плейсхолдеры (:name), приведения типов (::type) и числа;
 *  - не квотим SQL-ключевые слова (AND, ILIKE, CURRENT_TIMESTAMP, ...), имена функций (слово перед "("),
 *    префиксы типизированных литералов (DATE '2024-01-01') и единицы INTERVAL.
 *
 * Это не парсер SQL и не пытается быть им.
 */
final class ConditionQuoter
{
    /**
     * Слова, которые в условиях являются частью SQL-синтаксиса, а не именами колонок.
     */
    private const array KEYWORDS = [
        'ALL'               => true,
        'AND'               => true,
        'ANY'               => true,
        'AS'                => true,
        'BETWEEN'           => true,
        'BINARY'            => true,
        'CASE'              => true,
        'COLLATE'           => true,
        'CURRENT_DATE'      => true,
        'CURRENT_TIME'      => true,
        'CURRENT_TIMESTAMP' => true,
        'CURRENT_USER'      => true,
        'DISTINCT'          => true,
        'DIV'               => true,
        'ELSE'              => true,
        'END'               => true,
        'ESCAPE'            => true,
        'EXISTS'            => true,
        'FALSE'             => true,
        'FROM'              => true,
        'GLOB'              => true,
        'ILIKE'             => true,
        'IN'                => true,
        'INTERVAL'          => true,
        'IS'                => true,
        'LIKE'              => true,
        'LOCALTIME'         => true,
        'LOCALTIMESTAMP'    => true,
        'MOD'               => true,
        'NOT'               => true,
        'NULL'              => true,
        'ON'                => true,
        'OR'                => true,
        'REGEXP'            => true,
        'RLIKE'             => true,
        'SESSION_USER'      => true,
        'SIMILAR'           => true,
        'SOME'              => true,
        'THEN'              => true,
        'TO'                => true,
        'TRUE'              => true,
        'UNKNOWN'           => true,
        'WHEN'              => true,
        'XOR'               => true,
    ];

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

        // MySQL/MariaDB допускают экранирование обратным слэшем внутри строковых литералов.
        $literal = $this->quoter instanceof MySqlQuoter
            ? '\'(?:[^\'\\\\]|\\\\.|\'\')*\''
            : '\'(?:[^\']|\'\')*\'';

        $pattern = '/' . $literal
            . '|"(?:[^"]|"")*"'
            . '|`(?:[^`]|``)*`'
            . '|::?[A-Za-z_][A-Za-z0-9_]*'
            . '|0[xX][0-9A-Fa-f]+'
            . '|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?'
            . '|(?<word>[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z_][A-Za-z0-9_]*|\*))*)'
            . '/s';

        $out = preg_replace_callback(
            $pattern,
            function (array $match) use ($sql): string {
                $token = $match[0][0];
                $word  = $match['word'][0] ?? '';
                if ($word === '' || $word !== $token) {
                    return $token;
                }

                $offset = $match[0][1];
                if ($this->isSyntaxWord($sql, $word, $offset)) {
                    return $word;
                }

                return $this->quoter->dotted($word);
            },
            $sql,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        return is_string($out) ? $out : $sql;
    }

    private function isSyntaxWord(string $sql, string $word, int $offset): bool
    {
        if (!str_contains($word, '.') && isset(self::KEYWORDS[strtoupper($word)])) {
            return true;
        }

        // Имя функции (COUNT(...)) или префикс типизированного литерала (DATE '2024-01-01').
        $rest = ltrim(substr($sql, $offset + strlen($word)));
        if ($rest !== '' && ($rest[0] === '(' || $rest[0] === '\'')) {
            return true;
        }

        // Единица измерения после INTERVAL <значение>: INTERVAL 1 DAY, INTERVAL :days DAY.
        $before = substr($sql, 0, $offset);

        return preg_match('/\bINTERVAL\s+(?:\'(?:[^\']|\'\')*\'|\d+(?:\.\d+)?|:[A-Za-z_][A-Za-z0-9_]*|\?)\s*$/i', $before) === 1;
    }
}
