<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

/**
 * Описание внешнего ключа.
 */
final readonly class ForeignKeyDefinition
{
    public function __construct(
        public int $id,
        public int $seq,
        public string $table,
        public string $from,
        public string $to,
        public ?string $onUpdate = null,
        public ?string $onDelete = null,
        public ?string $match = null,
    ) {
    }
}
