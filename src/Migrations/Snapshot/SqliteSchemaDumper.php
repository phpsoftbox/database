<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_column;
use function in_array;

/**
 * Снимок схемы SQLite из sqlite_master: сначала таблицы, затем их индексы.
 *
 * Служебные таблицы `sqlite_*` и автоматические индексы не сохраняются.
 * Представления и триггеры не поддерживаются.
 */
final class SqliteSchemaDumper extends AbstractSchemaDumper
{
    public function tables(ConnectionInterface $connection): array
    {
        $rows = $connection->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name");

        return $this->filterPrefix($connection, array_column($rows, 'name'));
    }

    public function dump(ConnectionInterface $connection, array $tables, array $dataTables): array
    {
        $other = $connection->fetchAll('SELECT name FROM sqlite_master WHERE type IN (\'view\', \'trigger\')');
        if ($other !== []) {
            throw new ConfigurationException('Снимок схемы не поддерживает представления и триггеры.');
        }

        $rows = $connection->fetchAll(
            '
                SELECT
                    type,
                    name,
                    tbl_name,
                    sql
                FROM sqlite_master
                WHERE type IN (\'table\', \'index\')
                    AND sql IS NOT NULL
                ORDER BY type DESC, name
            ',
        );
        $statements = [];
        foreach ($rows as $row) {
            if (in_array($row['tbl_name'], $tables, true)) {
                $statements[] = (string) $row['sql'];
            }
        }
        foreach ($tables as $table) {
            if (in_array($table, $dataTables, true)) {
                $statements = [...$statements, ...$this->rows($connection, $table)];
            }
        }

        return $statements;
    }
}
