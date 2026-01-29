<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_values;
use function is_string;
use function ksort;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Introspection схемы SQLite.
 */
final readonly class SqliteSchemaManager implements SchemaManagerInterface
{
    use SchemaHelpersTrait;

    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    public function tables(): array
    {
        $rows = $this->connection->fetchAll(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        $tables = [];
        foreach ($rows as $row) {
            if (!isset($row['name']) || !is_string($row['name'])) {
                continue;
            }
            $tables[] = $row['name'];
        }

        return $tables;
    }

    public function hasTable(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $row = $this->connection->fetchOne(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name AND name NOT LIKE 'sqlite_%'",
            ['name' => $table],
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

    public function columns(string $table): array
    {
        $rows = $this->pragmaTableInfo($table);

        $columns = [];
        foreach ($rows as $row) {
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }

            $type    = isset($row['type']) && is_string($row['type']) ? $row['type'] : '';
            $notnull = isset($row['notnull']) ? (int) $row['notnull'] : 0;
            $dflt    = $row['dflt_value'] ?? null;
            $pk      = isset($row['pk']) ? (int) $row['pk'] : 0;

            $columns[] = new ColumnDefinition(
                name: $name,
                type: $type,
                nullable: $notnull === 0,
                default: $dflt,
                primaryKey: $pk > 0,
            );
        }

        return $columns;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $column = trim($column);
        if ($column === '') {
            return false;
        }

        foreach ($this->columns($table) as $col) {
            if ($col->name === $column) {
                return true;
            }
        }

        return false;
    }

    public function primaryKey(string $table): array
    {
        $rows = $this->pragmaTableInfo($table);

        $tmp = [];
        foreach ($rows as $row) {
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }

            $pk = isset($row['pk']) ? (int) $row['pk'] : 0;
            if ($pk > 0) {
                $tmp[$pk] = $name;
            }
        }

        if ($tmp === []) {
            return [];
        }

        ksort($tmp);

        return array_values($tmp);
    }

    public function indexes(string $table): array
    {
        $t    = $this->escapeIdentifier($table);
        $rows = $this->connection->fetchAll('PRAGMA index_list("' . $t . '")');

        $indexes = [];
        foreach ($rows as $row) {
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
            if ($name === '') {
                continue;
            }

            $unique  = isset($row['unique']) ? ((int) $row['unique'] === 1) : false;
            $origin  = isset($row['origin']) && is_string($row['origin']) ? $row['origin'] : null;
            $partial = isset($row['partial']) ? ((int) $row['partial'] === 1) : null;

            $cols = $this->indexColumns($name);

            $indexes[] = new IndexDefinition(
                name: $name,
                unique: $unique,
                columns: $cols,
                origin: $origin,
                partial: $partial,
            );
        }

        return $indexes;
    }

    public function foreignKeys(string $table): array
    {
        $t    = $this->escapeIdentifier($table);
        $rows = $this->connection->fetchAll('PRAGMA foreign_key_list("' . $t . '")');

        $fks = [];
        foreach ($rows as $row) {
            $id       = isset($row['id']) ? (int) $row['id'] : 0;
            $seq      = isset($row['seq']) ? (int) $row['seq'] : 0;
            $refTable = isset($row['table']) && is_string($row['table']) ? $row['table'] : '';
            $from     = isset($row['from']) && is_string($row['from']) ? $row['from'] : '';
            $to       = isset($row['to']) && is_string($row['to']) ? $row['to'] : '';

            if ($refTable === '' || $from === '' || $to === '') {
                continue;
            }

            $onUpdate = isset($row['on_update']) && is_string($row['on_update']) ? $row['on_update'] : null;
            $onDelete = isset($row['on_delete']) && is_string($row['on_delete']) ? $row['on_delete'] : null;
            $match    = isset($row['match']) && is_string($row['match']) ? $row['match'] : null;

            $fks[] = new ForeignKeyDefinition(
                id: $id,
                seq: $seq,
                table: $refTable,
                from: $from,
                to: $to,
                onUpdate: $onUpdate,
                onDelete: $onDelete,
                match: $match,
            );
        }

        return $fks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pragmaTableInfo(string $table): array
    {
        $t = $this->escapeIdentifier($table);

        return $this->connection->fetchAll('PRAGMA table_info("' . $t . '")');
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $indexName): array
    {
        $i    = $this->escapeIdentifier($indexName);
        $rows = $this->connection->fetchAll('PRAGMA index_info("' . $i . '")');

        $cols = [];
        foreach ($rows as $row) {
            $seqno = isset($row['seqno']) ? (int) $row['seqno'] : null;
            $name  = isset($row['name']) && is_string($row['name']) ? $row['name'] : null;
            if ($name === null || $name === '') {
                continue;
            }
            if ($seqno === null) {
                $cols[] = $name;
                continue;
            }
            $cols[$seqno] = $name;
        }

        if ($cols === []) {
            return [];
        }

        ksort($cols);
        /** @var list<string> $out */
        $out = array_values($cols);

        return $out;
    }

    private function escapeIdentifier(string $name): string
    {
        return str_replace('"', '""', $name);
    }
}
