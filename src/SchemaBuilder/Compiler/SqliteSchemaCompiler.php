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

final class SqliteSchemaCompiler extends AbstractSchemaCompiler
{
    protected function createTablePrefix(TableBlueprint $table): string
    {
        return $table->temporary ? 'CREATE TEMP TABLE' : 'CREATE TABLE';
    }

    protected function compileColumn(ColumnBlueprint $col, TableBlueprint $table): string
    {
        $name = $this->quoteIdentifier($col->name);

        if ($col->type === 'id') {
            return $name . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $type = match ($col->type) {
            'bigInteger' => 'INTEGER',
            'integer'    => 'INTEGER',
            'boolean'    => 'INTEGER',
            'text'       => 'TEXT',
            'string'     => 'TEXT',
            'json'       => 'TEXT',
            'datetime'   => 'TEXT',
            'date'       => 'TEXT',
            'time'       => 'TEXT',
            'timestamp'  => 'TEXT',
            default      => throw new ConfigurationException(sprintf('Unsupported column type "%s" for sqlite.', $col->type)),
        };

        $sql = $name . ' ' . $type;

        if (!$col->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->default !== null) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col->default);
        }

        return $sql;
    }

    protected function quoteIdentifier(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    private function compileDefault(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'NULL';
        }

        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
