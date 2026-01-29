<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Quoting;

/**
 * MySQL/MariaDB экранирование идентификаторов: `identifier`.
 */
final class MySqlQuoter extends AbstractQuoter
{
    protected function quoteChar(): string
    {
        return '`';
    }
}
