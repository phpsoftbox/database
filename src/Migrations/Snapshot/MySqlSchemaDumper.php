<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_values;
use function implode;
use function in_array;
use function preg_replace;
use function sort;

/**
 * Снимок схемы MySQL и MariaDB через SHOW CREATE TABLE.
 *
 * Счётчик AUTO_INCREMENT не сохраняется. Внешние ключи создаются при отключённой проверке,
 * поэтому порядок таблиц не важен. Представления и триггеры не поддерживаются.
 */
final class MySqlSchemaDumper extends AbstractSchemaDumper
{
    public function tables(ConnectionInterface $connection): array
    {
        return $this->filterPrefix($connection, $this->tablesOfType($connection, 'BASE TABLE'));
    }

    public function dump(ConnectionInterface $connection, array $tables, array $dataTables): array
    {
        $views = $this->filterPrefix($connection, $this->tablesOfType($connection, 'VIEW'));
        if ($views !== [] || $connection->fetchAll('SHOW TRIGGERS') !== []) {
            throw new ConfigurationException('Снимок схемы не поддерживает представления и триггеры: ' . implode(', ', $views));
        }

        sort($tables);
        $statements = [];
        foreach ($tables as $table) {
            $row          = $connection->fetchOne('SHOW CREATE TABLE ' . $connection->quoteIdentifier($table));
            $statements[] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) array_values($row ?? [])[1]);
        }
        foreach ($tables as $table) {
            if (in_array($table, $dataTables, true)) {
                $statements = [...$statements, ...$this->rows($connection, $table)];
            }
        }

        return $statements;
    }

    public function load(ConnectionInterface $connection, array $statements): void
    {
        $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            parent::load($connection, $statements);
        } finally {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @return list<string>
     */
    private function tablesOfType(ConnectionInterface $connection, string $type): array
    {
        $tables = [];
        foreach ($connection->fetchAll('SHOW FULL TABLES') as $row) {
            [$name, $tableType] = array_values($row);
            if ($tableType === $type) {
                $tables[] = (string) $name;
            }
        }

        return $tables;
    }
}
