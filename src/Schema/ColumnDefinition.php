<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

/**
 * Описание колонки таблицы.
 */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public mixed $default,
        public bool $primaryKey,
    ) {
    }
}
