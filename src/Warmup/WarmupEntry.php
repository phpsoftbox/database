<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

final readonly class WarmupEntry
{
    /**
     * @param array<string, mixed>|null $row
     * @param list<array<string, mixed>>|null $rows
     */
    private function __construct(
        public bool $exists,
        public ?array $row = null,
        public ?array $rows = null,
    ) {
    }

    public static function missing(): self
    {
        return new self(false);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function row(array $row): self
    {
        return new self(true, $row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function rows(array $rows): self
    {
        return new self($rows !== [], rows: $rows);
    }
}
