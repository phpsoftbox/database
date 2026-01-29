<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\SchemaBuilder\UseCurrentFormatsEnum;

use function implode;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function str_replace;

final class MariaDbSchemaCompiler extends AbstractSchemaCompiler
{
    protected function createTablePrefix(TableBlueprint $table): string
    {
        return $table->temporary ? 'CREATE TEMPORARY TABLE' : 'CREATE TABLE';
    }

    protected function createTableSuffix(TableBlueprint $table): string
    {
        $parts = [];
        if (is_string($table->engine) && $table->engine !== '') {
            $parts[] = 'ENGINE=' . $table->engine;
        }
        if (is_string($table->charset) && $table->charset !== '') {
            $parts[] = 'DEFAULT CHARSET=' . $table->charset;
        }
        if (is_string($table->collation) && $table->collation !== '') {
            $parts[] = 'COLLATE=' . $table->collation;
        }
        if (is_string($table->comment) && $table->comment !== '') {
            $parts[] = "COMMENT='" . str_replace("'", "''", $table->comment) . "'";
        }

        return implode(' ', $parts);
    }

    protected function compileColumn(ColumnBlueprint $col, TableBlueprint $table): string
    {
        $name = $this->quoteIdentifier($col->name);

        if ($col->type === 'id') {
            $sql = $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
            if (is_string($col->comment) && $col->comment !== '') {
                $sql .= " COMMENT '" . str_replace("'", "''", $col->comment) . "'";
            }

            return $sql;
        }

        $type = match ($col->type) {
            'bigInteger' => 'BIGINT',
            'integer'    => 'INT',
            'boolean'    => 'TINYINT(1)',
            'text'       => 'TEXT',
            'string'     => 'VARCHAR',
            'json'       => 'JSON',
            'datetime'   => 'DATETIME',
            'date'       => 'DATE',
            'time'       => 'TIME',
            'timestamp'  => 'TIMESTAMP',
            default      => throw new ConfigurationException(sprintf('Unsupported column type "%s" for mariadb.', $col->type)),
        };

        if ($col->unsigned && ($col->type === 'integer' || $col->type === 'bigInteger')) {
            $type .= ' UNSIGNED';
        }

        $sql = $name . ' ' . $type;

        if ($col->type === 'string') {
            $sql .= '(' . ($col->length ?? 255) . ')';
        }

        if (!$col->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->useCurrent || $col->useCurrentOnUpdate) {
            $targetType = $col->useCurrentFormat === UseCurrentFormatsEnum::TIMESTAMP ? 'timestamp' : 'datetime';
            if (in_array($col->type, ['datetime', 'timestamp'], true) && $col->type === $targetType) {
                if ($col->useCurrent) {
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                }
                if ($col->useCurrentOnUpdate) {
                    $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
                }
            }
        }

        if ($col->default !== null && !$col->useCurrent) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col->default);
        }

        if (is_string($col->comment) && $col->comment !== '') {
            $sql .= " COMMENT '" . str_replace("'", "''", $col->comment) . "'";
        }

        return $sql;
    }

    public function compileAlterTableAddColumns(TableBlueprint $table): array
    {
        $columns = $table->columns();
        if ($columns === []) {
            throw new ConfigurationException('ALTER TABLE requires at least one column.');
        }

        $tableSql = $this->quoteIdentifier($table->table);

        $out = [];
        foreach ($columns as $col) {
            $colSql = $this->compileColumn($col, $table);

            $sql = 'ALTER TABLE ' . $tableSql . ' ADD COLUMN ' . $colSql;

            // MariaDB/MySQL: поддержка FIRST/AFTER.
            if ($col->isFirst) {
                $sql .= ' FIRST';
            } elseif (is_string($col->afterColumn) && $col->afterColumn !== '') {
                $sql .= ' AFTER ' . $this->quoteIdentifier($col->afterColumn);
            }

            $out[] = $sql;
        }

        return $out;
    }

    protected function quoteIdentifier(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
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
