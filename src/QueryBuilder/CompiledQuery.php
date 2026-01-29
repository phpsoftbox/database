<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

final class CompiledQuery
{
    /**
     * @param array<string|int, mixed> $bindings
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings = [],
    ) {
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toArray(): array
    {
        return [
            'sql'    => $this->sql,
            'params' => $this->bindings,
        ];
    }
}
