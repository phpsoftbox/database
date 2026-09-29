<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

/**
 * SpyConnection, для которой любые запросы fetchOne() возвращают строку: introspection считает таблицы существующими.
 */
final class ExistingTablesSpyConnection extends SpyConnection
{
    public function fetchOne(string $sql, array $params = []): ?array
    {
        parent::fetchOne($sql, $params);

        return ['ok' => 1];
    }
}
