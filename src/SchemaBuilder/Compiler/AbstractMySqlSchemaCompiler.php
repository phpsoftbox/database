<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\CharsetCollationName;
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

abstract class AbstractMySqlSchemaCompiler extends AbstractSchemaCompiler
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
        if ($table->charset !== null) {
            $parts[] = 'DEFAULT CHARSET=' . CharsetCollationName::normalize($table->charset, 'charset');
        }
        if ($table->collation !== null) {
            $parts[] = 'COLLATE=' . CharsetCollationName::normalize($table->collation, 'collation');
        }
        if (is_string($table->comment) && $table->comment !== '') {
            $parts[] = "COMMENT='" . str_replace("'", "''", $table->comment) . "'";
        }

        return implode(' ', $parts);
    }

    protected function compileColumnDefinition(ColumnBlueprint $col, TableBlueprint $table): string
    {
        $name = $this->quoteIdentifier($col->name);

        if ($col->type === 'id') {
            $sql = $name . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
            if (!$col->change) {
                $sql .= ' PRIMARY KEY';
            }
            if (is_string($col->comment) && $col->comment !== '') {
                $sql .= " COMMENT '" . str_replace("'", "''", $col->comment) . "'";
            }

            return $sql;
        }

        $type = match ($col->type) {
            'bigInteger' => 'BIGINT',
            'integer'    => 'INT',
            'decimal'    => 'DECIMAL',
            'boolean'    => 'TINYINT(1)',
            'text'       => 'TEXT',
            'string'     => 'VARCHAR',
            'json'       => 'JSON',
            'datetime'   => 'DATETIME',
            'date'       => 'DATE',
            'time'       => 'TIME',
            'timestamp'  => 'TIMESTAMP',
            default      => throw new ConfigurationException(sprintf(
                'Unsupported column type "%s" for %s.',
                $col->type,
                $this->dialectName(),
            )),
        };

        if ($col->unsigned && ($col->type === 'integer' || $col->type === 'bigInteger')) {
            $type .= ' UNSIGNED';
        }

        $sql = $name . ' ' . $type;

        if ($col->type === 'string') {
            $sql .= '(' . ($col->length ?? 255) . ')';
        }

        if ($col->type === 'decimal') {
            $sql .= '(' . ($col->precision ?? 10) . ',' . ($col->scale ?? 2) . ')';
        }

        if ($col->charset !== null) {
            $sql .= ' CHARACTER SET ' . $this->quoteIdentifier(CharsetCollationName::normalize($col->charset, 'charset'));
        }
        if ($col->collation !== null) {
            $sql .= ' COLLATE ' . $this->quoteIdentifier(CharsetCollationName::normalize($col->collation, 'collation'));
        }

        if ($col->generatedExpression !== null) {
            $sql .= ' GENERATED ALWAYS AS (' . $col->generatedExpression . ')';
            $sql .= $col->generatedStored ? ' STORED' : ' VIRTUAL';
        }

        if (
            !$col->nullable
            && ($col->generatedExpression === null || $this->supportsGeneratedColumnNullability())
        ) {
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

        if ($this->hasDefault($col) && !$col->useCurrent) {
            $sql .= ' DEFAULT ' . $this->compileDefault($col->default);
        }

        if ($col->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        if (is_string($col->comment) && $col->comment !== '') {
            $sql .= " COMMENT '" . str_replace("'", "''", $col->comment) . "'";
        }

        return $sql;
    }

    public function compileAlterTableAddColumns(TableBlueprint $table): array
    {
        $columns = $table->addedColumns();
        if ($columns === []) {
            return [];
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

    public function compileAlterTableChangeColumns(TableBlueprint $table): array
    {
        $columns = $table->changedColumns();
        if ($columns === []) {
            return [];
        }

        $tableSql = $this->quoteIdentifier($table->table);

        $out = [];
        foreach ($columns as $col) {
            $sql = 'ALTER TABLE ' . $tableSql . ' MODIFY COLUMN ' . $this->compileColumn($col, $table);

            if ($col->isFirst) {
                $sql .= ' FIRST';
            } elseif (is_string($col->afterColumn) && $col->afterColumn !== '') {
                $sql .= ' AFTER ' . $this->quoteIdentifier($col->afterColumn);
            }

            $out[] = $sql;
        }

        return $out;
    }

    public function compileDropIndexes(TableBlueprint $table): array
    {
        $tableSql = $this->quoteIdentifier($table->table);

        $out = [];
        foreach ($table->droppedIndexes() as $index) {
            $out[] = 'ALTER TABLE ' . $tableSql . ' DROP INDEX ' . $this->quoteIdentifier($index);
        }

        return $out;
    }

    public function compileAlterTableDropForeignKeys(TableBlueprint $table): array
    {
        $tableSql = $this->quoteIdentifier($table->table);

        $out = [];
        foreach ($table->droppedForeignKeys() as $foreignKey) {
            $out[] = 'ALTER TABLE ' . $tableSql . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($foreignKey);
        }

        return $out;
    }

    protected function quoteIdentifier(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
    }

    protected function supportsGeneratedColumnNullability(): bool
    {
        return true;
    }

    protected function supportsColumnCharacterOptions(): bool
    {
        return true;
    }

    abstract protected function dialectName(): string;

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
