<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

/**
 * Описание таблицы схемы.
 */
final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param list<IndexDefinition> $indexes
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $indexes,
        public array $foreignKeys,
    ) {
    }
}
