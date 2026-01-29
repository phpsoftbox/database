<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

use function is_bool;
use function is_float;
use function is_int;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

final class PostgresSchemaCompiler extends AbstractSchemaCompiler
{
    protected function createTablePrefix(TableBlueprint $table): string
    {
        return $table->temporary ? 'CREATE TEMP TABLE' : 'CREATE TABLE';
    }

    protected function compileColumn(ColumnBlueprint $col, TableBlueprint $table): string
    {
        $name = $this->quoteIdentifier($col->name);

        if ($col->type === 'id') {
            return $name . ' SERIAL PRIMARY KEY';
        }

        // unsigned/useCurrent/useCurrentOnUpdate игнорируем (driver-specific)

        $sql = $name . ' ' . $this->compileType($col);

        if ($col->generatedExpression !== null) {
            $sql .= ' GENERATED ALWAYS AS (' . $col->generatedExpression . ')';
            if ($col->generatedStored) {
                $sql .= ' STORED';
            }
        }

        if (!$col->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->default !== null && $col->generatedExpression === null) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col->default);
        }

        return $sql;
    }

    public function compileAlterTableChangeColumns(TableBlueprint $table): array
    {
        $columns = $table->changedColumns();
        if ($columns === []) {
            return [];
        }

        $tableSql = $this->quoteIdentifier($table->table);
        $out      = [];

        foreach ($columns as $col) {
            if ($col->type === 'id') {
                throw new ConfigurationException('Changing id columns is not supported by postgres schema compiler.');
            }

            $columnSql = $this->quoteIdentifier($col->name);

            $out[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $columnSql . ' DROP DEFAULT';
            $out[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $columnSql . ' TYPE ' . $this->compileType($col);

            if ($col->generatedExpression !== null) {
                $out[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $columnSql . ' SET EXPRESSION AS (' . $col->generatedExpression . ')';
            }

            $out[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $columnSql . ($col->nullable ? ' DROP NOT NULL' : ' SET NOT NULL');

            if ($col->default !== null && $col->generatedExpression === null) {
                $out[] = 'ALTER TABLE ' . $tableSql . ' ALTER COLUMN ' . $columnSql . ' SET DEFAULT ' . $this->compileDefault($col->default);
            }
        }

        return $out;
    }

    public function compileCreateExtensionIfNotExists(string $extension): string
    {
        return 'CREATE EXTENSION IF NOT EXISTS ' . $this->quoteIdentifier($this->normalizeExtension($extension));
    }

    public function compileDropExtensionIfExists(string $extension): string
    {
        return 'DROP EXTENSION IF EXISTS ' . $this->quoteIdentifier($this->normalizeExtension($extension));
    }

    protected function supportsIndexMethod(string $method): bool
    {
        return strtolower($method) === 'gin';
    }

    protected function quoteIdentifier(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    private function compileType(ColumnBlueprint $col): string
    {
        $type = match ($col->type) {
            'bigInteger' => 'BIGINT',
            'integer'    => 'INTEGER',
            'decimal'    => 'NUMERIC',
            'boolean'    => 'BOOLEAN',
            'text'       => 'TEXT',
            'string'     => 'VARCHAR',
            'json'       => 'JSONB',
            'datetime'   => 'TIMESTAMP',
            'date'       => 'DATE',
            'time'       => 'TIME',
            'timestamp'  => 'TIMESTAMP',
            'tsvector'   => 'TSVECTOR',
            default      => throw new ConfigurationException(sprintf('Unsupported column type "%s" for postgres.', $col->type)),
        };

        if ($col->type === 'string') {
            $type .= '(' . ($col->length ?? 255) . ')';
        }

        if ($col->type === 'decimal') {
            $type .= '(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 2) . ')';
        }

        return $type;
    }

    private function compileDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'NULL';
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = trim($extension);
        if ($extension === '') {
            throw new ConfigurationException('PostgreSQL extension name must be non-empty.');
        }

        return $extension;
    }
}
