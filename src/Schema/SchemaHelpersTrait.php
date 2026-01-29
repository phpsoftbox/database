<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

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

        foreach ($this->columns($table) as $col) {
            if ($col->name === $column) {
                return $col;
            }
        }

        return null;
    }

    public function index(string $table, string $index): ?IndexDefinition
    {
        $index = trim($index);
        if ($index === '') {
            return null;
        }

        foreach ($this->indexes($table) as $idx) {
            if ($idx->name === $index) {
                return $idx;
            }
        }

        return null;
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
