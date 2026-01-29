<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_flip;
use function is_string;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Introspection схемы PostgreSQL.
 *
 * Реализовано через information_schema / pg_catalog.
 * По умолчанию работаем со схемой current_schema().
 */
final readonly class PostgresSchemaManager implements SchemaManagerInterface
{
    use SchemaHelpersTrait;

    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @return list<string>
     * @noinspection SqlNoDataSourceInspection
     */
    public function tables(): array
    {
        $rows = $this->connection->fetchAll("
            SELECT table_name AS name
            FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ");

        $out = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @noinspection SqlNoDataSourceInspection
     */
    public function hasTable(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $row = $this->connection->fetchOne(
            'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :t LIMIT 1',
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
     * @noinspection SqlNoDataSourceInspection
     */
    public function columns(string $table): array
    {
        $rows = $this->connection->fetchAll('
            SELECT column_name, data_type, is_nullable, column_default
            FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = :table
            ORDER BY ordinal_position
        ', [
            'table' => $table,
        ]);

        $pkCols = array_flip($this->primaryKey($table));

        $out = [];
        foreach ($rows as $row) {
            $name = $row['column_name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $type     = is_string($row['data_type'] ?? null) ? (string) $row['data_type'] : '';
            $nullable = strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES';
            $default  = $row['column_default'] ?? null;
            $primary  = isset($pkCols[$name]);

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

    /**
     * @noinspection SqlNoDataSourceInspection
     */
    public function hasColumn(string $table, string $column): bool
    {
        $table  = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $row = $this->connection->fetchOne('
            SELECT 1 AS ok 
            FROM information_schema.columns 
            WHERE table_schema = current_schema() 
            AND table_name = :table
            AND column_name = :column LIMIT 1
        ', [
            'table'  => $table,
            'column' => $column,
        ]);

        return $row !== null;
    }

    /**
     * @return list<string>
     * @noinspection SqlNoDataSourceInspection
     */
    public function primaryKey(string $table): array
    {
        $rows = $this->connection->fetchAll("
            SELECT kcu.column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
            ON kcu.constraint_name = tc.constraint_name
            AND kcu.table_schema = tc.table_schema
            AND kcu.table_name = tc.table_name
            WHERE tc.table_schema = current_schema() and tc.table_name = :t and tc.constraint_type = 'PRIMARY KEY'
            ORDER BY kcu.ordinal_position
        ", [
            't' => $table,
        ]);

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
     * @noinspection SqlNoDataSourceInspection
     */
    public function indexes(string $table): array
    {
        $rows = $this->connection->fetchAll('
            SELECT 
                i.relname AS name, 
                ix.indisunique AS unique, 
                a.attname AS column_name, x.n AS ord
            FROM pg_class t
            JOIN pg_namespace ns ON ns.oid = t.relnamespace
            JOIN pg_index ix ON ix.indrelid = t.oid
            JOIN pg_class i ON i.oid = ix.indexrelid
            JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS x(attnum, n) ON true
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = x.attnum
            WHERE ns.nspname = current_schema() AND t.relname = :t
            ORDER BY i.relname, x.n
        ', [
            't' => $table,
        ]);

        /** @var array<string, array{unique:bool, cols:list<string>}> $tmp */
        $tmp = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $col  = $row['column_name'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($col) || $col === '') {
                continue;
            }

            $unique = (bool) ($row['unique'] ?? false);
            if (!isset($tmp[$name])) {
                $tmp[$name] = ['unique' => $unique, 'cols' => []];
            }
            $tmp[$name]['cols'][] = $col;
        }

        $out = [];
        foreach ($tmp as $name => $data) {
            $out[] = new IndexDefinition(
                name: $name,
                unique: $data['unique'],
                columns: $data['cols'],
                origin: null,
                partial: null,
            );
        }

        return $out;
    }

    /**
     * @return list<ForeignKeyDefinition>
     * @noinspection SqlNoDataSourceInspection
     */
    public function foreignKeys(string $table): array
    {
        $rows = $this->connection->fetchAll("
            SELECT
                c.conname AS constraint_name,
                ft.relname AS referenced_table,
                fa.attname AS from_column,
                ta.attname AS to_column,
                c.confupdtype AS on_update,
                c.confdeltype AS on_delete,
                x.n AS ord
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace ns ON ns.oid = t.relnamespace
            JOIN pg_class ft ON ft.oid = c.confrelid
            JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS x(attnum, n) ON true
            JOIN pg_attribute fa ON fa.attrelid = t.oid AND fa.attnum = x.attnum
            JOIN LATERAL unnest(c.confkey) WITH ORDINALITY AS y(attnum, n) ON y.n = x.n
            JOIN pg_attribute ta ON ta.attrelid = ft.oid AND ta.attnum = y.attnum
            WHERE ns.nspname = current_schema() AND t.relname = :t AND c.contype = 'f'
            ORDER BY c.conname, x.n
        ", [
            't' => $table,
        ]);

        $out = [];
        $id  = 0;
        foreach ($rows as $row) {
            $refTable = $row['referenced_table'] ?? null;
            $from     = $row['from_column'] ?? null;
            $to       = $row['to_column'] ?? null;
            if (!is_string($refTable) || $refTable === '' || !is_string($from) || $from === '' || !is_string($to) || $to === '') {
                continue;
            }

            $seq = isset($row['ord']) ? ((int) $row['ord'] - 1) : 0;

            $out[] = new ForeignKeyDefinition(
                id: $id++,
                seq: $seq,
                table: $refTable,
                from: $from,
                to: $to,
                onUpdate: is_string($row['on_update'] ?? null) ? (string) $row['on_update'] : null,
                onDelete: is_string($row['on_delete'] ?? null) ? (string) $row['on_delete'] : null,
                match: null,
            );
        }

        return $out;
    }
}
