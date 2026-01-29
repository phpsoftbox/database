<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

/**
 * Операция переименования колонки.
 */
final readonly class ColumnRenameBlueprint
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
    }
}
