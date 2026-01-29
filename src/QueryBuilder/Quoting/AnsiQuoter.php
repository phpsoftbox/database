<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Quoting;

/**
 * ANSI-экранирование идентификаторов: "identifier".
 *
 * Подходит для PostgreSQL и SQLite.
 */
final class AnsiQuoter extends AbstractQuoter
{
    protected function quoteChar(): string
    {
        return '"';
    }
}
