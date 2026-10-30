<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_check_encoding;
use function str_starts_with;

abstract class AbstractSchemaDumper implements SchemaDumperInterface
{
    public function load(ConnectionInterface $connection, array $statements): void
    {
        foreach ($statements as $statement) {
            $connection->execute($statement);
        }
    }

    /**
     * @param list<string> $tables
     * @return list<string>
     */
    protected function filterPrefix(ConnectionInterface $connection, array $tables): array
    {
        $prefix = $connection->prefix();

        return array_values(array_filter($tables, static fn (string $table): bool => $prefix === '' || str_starts_with($table, $prefix)));
    }

    /**
     * Строки таблицы в виде отдельных INSERT, по порядку первой колонки.
     *
     * @return list<string>
     */
    protected function rows(ConnectionInterface $connection, string $table): array
    {
        $quoted     = $connection->quoteIdentifier($table);
        $statements = [];
        foreach ($connection->fetchAll('SELECT * FROM ' . $quoted . ' ORDER BY 1') as $row) {
            $columns      = implode(', ', array_map($connection->quoteIdentifier(...), array_keys($row)));
            $values       = implode(', ', array_map(fn (mixed $value): string => $this->literal($connection, $value), $row));
            $statements[] = 'INSERT INTO ' . $quoted . ' (' . $columns . ') VALUES (' . $values . ')';
        }

        return $statements;
    }

    private function literal(ConnectionInterface $connection, mixed $value): string
    {
        return match (true) {
            $value === null                                         => 'NULL',
            is_bool($value)                                         => $value ? '1' : '0',
            is_int($value), is_float($value)                        => (string) $value,
            is_string($value) && mb_check_encoding($value, 'UTF-8') => $connection->pdo()->quote($value),
            is_string($value)                                       => "X'" . bin2hex($value) . "'",
            default                                                 => throw new ConfigurationException('Неподдерживаемое значение в таблице с данными снимка.'),
        };
    }
}
