<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use function array_filter;
use function array_find;
use function array_values;
use function trim;

/**
 * Набор дефолтных helper-методов для SchemaManager.
 *
 * Реализации могут переопределять эти методы для оптимизации,
 * но для MVP достаточно дефолтной логики через уже существующие методы.
 */
trait SchemaHelpersTrait
{
    public function column(string $table, string $column): ?ColumnDefinition
    {
        $column = trim($column);
        if ($column === '') {
            return null;
        }

        return array_find($this->columns($table), fn ($col) => $col->name === $column);
    }

    /**
     * @param list<string> $columns
     */
    public function missingColumns(string $table, array $columns): MissingColumnsResult
    {
        $existing = [];
        foreach ($this->columns($table) as $column) {
            $existing[$column->name] = true;
        }

        $missing = array_values(array_filter(
            $columns,
            static function (string $column) use ($existing): bool {
                $column = trim($column);

                return $column !== '' && !isset($existing[$column]);
            },
        ));

        return new MissingColumnsResult($missing);
    }

    public function index(string $table, string $index): ?IndexDefinition
    {
        $index = trim($index);
        if ($index === '') {
            return null;
        }

        return array_find($this->indexes($table), fn ($idx) => $idx->name === $index);
    }

    public function hasIndex(string $table, string $index): bool
    {
        return $this->index($table, $index) !== null;
    }

    public function foreignKey(string $table, string $foreignKey): ?ForeignKeyDefinition
    {
        $foreignKey = trim($foreignKey);
        if ($foreignKey === '') {
            return null;
        }

        return array_find($this->foreignKeys($table), fn ($fk) => $fk->name === $foreignKey);
    }

    public function hasForeignKey(string $table, string $foreignKey): bool
    {
        return $this->foreignKey($table, $foreignKey) !== null;
    }

    /**
     * Возвращает внешние ключи, которые исходят из указанной колонки (удобно для миграций).
     *
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeysByColumn(string $table, string $column): array
    {
        $column = trim($column);
        if ($column === '') {
            return [];
        }

        $out = [];
        foreach ($this->foreignKeys($table) as $fk) {
            if ($fk->from === $column) {
                $out[] = $fk;
            }
        }

        return $out;
    }
}
