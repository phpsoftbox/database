<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use function trim;

/**
 * Raw SQL expression.
 *
 * Нужна для случаев, когда QueryBuilder не должен применять никакие правила (префиксы и т.д.),
 * а обязан "как есть" встроить фрагмент SQL.
 */
final readonly class Expression
{
    public string $sql;

    public function __construct(string $sql)
    {
        $this->sql = trim($sql);
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
