<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_values;
use function is_string;
use function ksort;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Introspection схемы MariaDB/MySQL.
 *
 * Реализовано через information_schema.*
 */
final readonly class MariaDbSchemaManager implements SchemaManagerInterface
{
    use SchemaHelpersTrait;

    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT table_name AS name '
            . 'FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_type = "BASE TABLE" '
            . 'ORDER BY table_name',
        );

        $out = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    public function hasTable(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $row = $this->connection->fetchOne(
            'SELECT 1 AS ok '
            . 'FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :t '
            . 'LIMIT 1',
            ['t' => $table],
        );

        return $row !== null;
    }

    public function table(string $table): TableDefinition
    {
        if (!$this->hasTable($table)) {
            throw new ConfigurationException(sprintf('Table "%s" does not exist.', $table));
        }

        return new TableDefinition(
            name: $table,
            columns: $this->columns($table),
            indexes: $this->indexes($table),
            foreignKeys: $this->foreignKeys($table),
        );
    }

    /**
     * @return list<ColumnDefinition>
     */
    public function columns(string $table): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT column_name, column_type, is_nullable, column_default, column_key '
            . 'FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :t '
            . 'ORDER BY ordinal_position',
            ['t' => $table],
        );

        $out = [];
        foreach ($rows as $row) {
            $name = $row['column_name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $type     = is_string($row['column_type'] ?? null) ? (string) $row['column_type'] : '';
            $nullable = strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES';
            $default  = $row['column_default'] ?? null;
            $primary  = ((string) ($row['column_key'] ?? '')) === 'PRI';

            $out[] = new ColumnDefinition(
                name: $name,
                type: $type,
                nullable: $nullable,
                default: $default,
                primaryKey: $primary,
            );
        }

        return $out;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $table  = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $row = $this->connection->fetchOne(
            'SELECT 1 AS ok '
            . 'FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c '
            . 'LIMIT 1',
            ['t' => $table, 'c' => $column],
        );

        return $row !== null;
    }

    /**
     * @return list<string>
     */
    public function primaryKey(string $table): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT k.column_name '
            . 'FROM information_schema.table_constraints tc '
            . 'JOIN information_schema.key_column_usage k '
            . '  ON k.constraint_name = tc.constraint_name '
            . ' AND k.table_schema = tc.table_schema '
            . ' AND k.table_name = tc.table_name '
            . 'WHERE tc.table_schema = DATABASE() AND tc.table_name = :t AND tc.constraint_type = "PRIMARY KEY" '
            . 'ORDER BY k.ordinal_position',
            ['t' => $table],
        );

        $out = [];
        foreach ($rows as $row) {
            $name = $row['column_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return list<IndexDefinition>
     */
    public function indexes(string $table): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT index_name, non_unique, seq_in_index, column_name '
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :t '
            . 'ORDER BY index_name, seq_in_index',
            ['t' => $table],
        );

        /** @var array<string, array{unique:bool, cols: array<int, string>}> $tmp */
        $tmp = [];
        foreach ($rows as $row) {
            $indexName = $row['index_name'] ?? null;
            $col       = $row['column_name'] ?? null;
            if (!is_string($indexName) || $indexName === '' || !is_string($col) || $col === '') {
                continue;
            }

            $nonUnique = isset($row['non_unique']) ? (int) $row['non_unique'] : 1;
            $seq       = isset($row['seq_in_index']) ? (int) $row['seq_in_index'] : 0;

            if (!isset($tmp[$indexName])) {
                $tmp[$indexName] = ['unique' => $nonUnique === 0, 'cols' => []];
            }
            $tmp[$indexName]['cols'][$seq] = $col;
        }

        $out = [];
        foreach ($tmp as $name => $data) {
            ksort($data['cols']);
            $cols = array_values($data['cols']);

            $out[] = new IndexDefinition(
                name: $name,
                unique: $data['unique'],
                columns: $cols,
                origin: null,
                partial: null,
            );
        }

        return $out;
    }

    /**
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(string $table): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT k.constraint_name, k.ordinal_position, k.referenced_table_name, k.column_name, k.referenced_column_name, '
            . '       rc.update_rule, rc.delete_rule '
            . 'FROM information_schema.key_column_usage k '
            . 'JOIN information_schema.referential_constraints rc '
            . '  ON rc.constraint_schema = k.table_schema '
            . ' AND rc.table_name = k.table_name '
            . ' AND rc.constraint_name = k.constraint_name '
            . 'WHERE k.table_schema = DATABASE() AND k.table_name = :t AND k.referenced_table_name IS NOT NULL '
            . 'ORDER BY k.constraint_name, k.ordinal_position',
            ['t' => $table],
        );

        $out = [];
        $id  = 0;
        foreach ($rows as $row) {
            $refTable = $row['referenced_table_name'] ?? null;
            $from     = $row['column_name'] ?? null;
            $to       = $row['referenced_column_name'] ?? null;
            if (!is_string($refTable) || $refTable === '' || !is_string($from) || $from === '' || !is_string($to) || $to === '') {
                continue;
            }

            $seq      = isset($row['ordinal_position']) ? ((int) $row['ordinal_position'] - 1) : 0;
            $onUpdate = is_string($row['update_rule'] ?? null) ? (string) $row['update_rule'] : null;
            $onDelete = is_string($row['delete_rule'] ?? null) ? (string) $row['delete_rule'] : null;

            $out[] = new ForeignKeyDefinition(
                id: $id++,
                seq: $seq,
                table: $refTable,
                from: $from,
                to: $to,
                onUpdate: $onUpdate,
                onDelete: $onDelete,
                match: null,
            );
        }

        return $out;
    }
}
