<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

/**
 * Описание индекса.
 */
final readonly class IndexDefinition
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public bool $unique,
        public array $columns,
        public ?string $origin = null,
        public ?bool $partial = null,
    ) {
    }
}
