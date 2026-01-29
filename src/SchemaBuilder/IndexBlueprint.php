<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

/**
 * Чертёж индекса.
 */
final readonly class IndexBlueprint
{
    /**
     * @param non-empty-list<string> $columns
     */
    public function __construct(
        public array $columns,
        public ?string $name = null,
        public bool $unique = false,
    ) {
    }
}
