<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\SchemaBuilder\ColumnBlueprint;
use PhpSoftBox\Database\SchemaBuilder\ForeignKeyBlueprint;
use PhpSoftBox\Database\SchemaBuilder\IndexBlueprint;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

use function array_map;
use function array_merge;
use function implode;
use function is_string;
use function preg_replace;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Базовый компилятор схемы.
 *
 * Общая логика:
 * - CREATE TABLE
 * - DROP TABLE IF EXISTS
 *
 * Драйвер-специфичная часть:
 * - компиляция колонки
 * - quoting идентификаторов
 */
abstract class AbstractSchemaCompiler implements SchemaCompilerInterface
{
    final public function compileCreateTable(TableBlueprint $table): string
    {
        $columnsSql = [];
        foreach ($table->columns() as $col) {
            $columnsSql[] = $this->compileColumn($col, $table);
        }

        if ($columnsSql === []) {
            throw new ConfigurationException('Cannot create table without columns.');
        }

        $colsSql    = $columnsSql;
        $foreignSql = $this->compileForeignKeys($table);
        if ($foreignSql !== []) {
            $colsSql = array_merge($colsSql, $foreignSql);
        }

        $prefix = $this->createTablePrefix($table);
        $suffix = $this->createTableSuffix($table);

        $sql = $prefix . ' ' . $this->quoteIdentifier($table->table) . ' (' . implode(', ', $colsSql) . ')';
        if ($suffix !== '') {
            $sql .= ' ' . $suffix;
        }

        return $sql;
    }

    final public function compileCreateTableIfNotExists(TableBlueprint $table): string
    {
        $sql = $this->compileCreateTable($table);

        // Переносимый вариант: CREATE TABLE -> CREATE TABLE IF NOT EXISTS
        // MariaDB/MySQL/SQLite/Postgres это поддерживают.
        return preg_replace('/^CREATE\s+TABLE\b/i', 'CREATE TABLE IF NOT EXISTS', $sql, 1) ?? $sql;
    }

    final public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table);
    }

    final public function compileDropTable(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteIdentifier($table);
    }

    final public function compileRenameTable(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($from) . ' RENAME TO ' . $this->quoteIdentifier($to);
    }

    final protected function compileNameAndType(string $name, ?string $type = null): string
    {
        return $this->quoteIdentifier($name) . ($type ? ' ' . $type : '');
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
            $out[]  = 'ALTER TABLE ' . $tableSql . ' ADD COLUMN ' . $colSql;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    final public function compileCreateIndexes(TableBlueprint $table): array
    {
        $indexes = $table->indexes();

        // Авто-индексы, выставленные на уровне колонок.
        foreach ($table->columns() as $col) {
            if ($col->uniqueName !== null) {
                $indexes[] = new IndexBlueprint([
                    $col->name,
                ], $col->uniqueName, true);
            }
            if ($col->indexName !== null) {
                $indexes[] = new IndexBlueprint([
                    $col->name,
                ], $col->indexName, false);
            }
        }

        $tableName = $table->table;

        $out = [];
        foreach ($indexes as $idx) {
            $out[] = $this->compileCreateIndex($tableName, $idx);
        }

        return $out;
    }

    final protected function compileCreateIndex(string $table, IndexBlueprint $idx): string
    {
        $columns = $idx->columns;
        if ($columns === []) {
            throw new ConfigurationException('Index must contain at least one column.');
        }

        $indexName = $idx->name;
        if (!is_string($indexName) || trim($indexName) === '') {
            $indexName = $this->defaultIndexName($table, $columns, $idx->unique);
        }

        $colsSql = [];
        foreach ($columns as $col) {
            $colsSql[] = $this->quoteIdentifier($col);
        }

        return sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s)',
            $idx->unique ? 'UNIQUE ' : '',
            $this->quoteIdentifier($indexName),
            $this->quoteIdentifier($table),
            implode(', ', $colsSql),
        );
    }

    /**
     * @param non-empty-list<string> $columns
     */
    final protected function defaultIndexName(string $table, array $columns, bool $unique): string
    {
        $base = $table . '_' . implode('_', $columns);

        return $unique ? $base . '_unique' : $base . '_index';
    }

    /**
     * @param non-empty-list<string> $columns
     */
    final protected function defaultForeignKeyName(string $table, array $columns): string
    {
        return $table . '_' . implode('_', $columns) . '_fk';
    }

    abstract protected function compileColumn(ColumnBlueprint $col, TableBlueprint $table): string;

    /**
     * @return list<string>
     */
    final protected function compileForeignKeys(TableBlueprint $table): array
    {
        $keys = $table->foreignKeys();
        if ($keys === []) {
            return [];
        }

        $out = [];
        foreach ($keys as $fk) {
            $out[] = $this->compileForeignKey($table, $fk);
        }

        return $out;
    }

    protected function compileForeignKey(TableBlueprint $table, ForeignKeyBlueprint $fk): string
    {
        $name = $fk->name;
        if (!is_string($name) || trim($name) === '') {
            $name = $this->defaultForeignKeyName($table->table, $fk->columns);
        }

        $columns    = array_map(fn (string $col): string => $this->quoteIdentifier($col), $fk->columns);
        $refColumns = array_map(fn (string $col): string => $this->quoteIdentifier($col), $fk->refColumns);

        $sql = 'CONSTRAINT ' . $this->quoteIdentifier($name)
            . ' FOREIGN KEY (' . implode(', ', $columns) . ')'
            . ' REFERENCES ' . $this->quoteIdentifier($fk->refTable)
            . ' (' . implode(', ', $refColumns) . ')';

        if (is_string($fk->onDelete) && $fk->onDelete !== '') {
            $sql .= ' ON DELETE ' . strtoupper($fk->onDelete);
        }
        if (is_string($fk->onUpdate) && $fk->onUpdate !== '') {
            $sql .= ' ON UPDATE ' . strtoupper($fk->onUpdate);
        }

        return $sql;
    }

    /**
     * Экранирует идентификатор (таблица/колонка/индекс) согласно SQL-диалекту.
     */
    abstract protected function quoteIdentifier(string $ident): string;

    /**
     * Часть CREATE TABLE до имени таблицы.
     */
    protected function createTablePrefix(TableBlueprint $table): string
    {
        // По умолчанию игнорируем temporary/engine/charset/collation/comment.
        return 'CREATE TABLE';
    }

    /**
     * Часть CREATE TABLE после списка колонок.
     */
    protected function createTableSuffix(TableBlueprint $table): string
    {
        return '';
    }
}
