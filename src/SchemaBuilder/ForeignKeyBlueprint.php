<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function count;
use function trim;

final class ForeignKeyBlueprint
{
    /**
     * @param non-empty-list<string> $columns
     * @param non-empty-list<string> $refColumns
     */
    public function __construct(
        public readonly array $columns,
        public readonly string $refTable,
        public readonly array $refColumns,
        public ?string $name = null,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
    ) {
        if ($this->refTable === '') {
            throw new ConfigurationException('Foreign key reference table must be non-empty.');
        }

        if (count($this->columns) !== count($this->refColumns)) {
            throw new ConfigurationException('Foreign key columns count must match referenced columns count.');
        }
    }

    public function onDelete(string $action): self
    {
        $action = trim($action);
        if ($action !== '') {
            $this->onDelete = $action;
        }

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $action = trim($action);
        if ($action !== '') {
            $this->onUpdate = $action;
        }

        return $this;
    }
}
