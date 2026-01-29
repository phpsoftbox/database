<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Postgres\FullText;

use InvalidArgumentException;
use Stringable;

use function array_map;
use function explode;
use function implode;
use function preg_match;
use function str_replace;
use function trim;

final class PgSearchVectorExpression implements Stringable
{
    private string $config;

    /**
     * @var list<array{sql: string, weight: ?string}>
     */
    private array $parts = [];

    public function __construct(string $config = 'simple')
    {
        $config = trim($config);
        if ($config === '') {
            throw new InvalidArgumentException('PostgreSQL full-text config must be non-empty.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $config) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL full-text config: ' . $config);
        }

        $this->config = $config;
    }

    public static function make(string $config = 'simple'): self
    {
        return new self($config);
    }

    public function column(string $column, ?string $weight = null): self
    {
        $column = trim($column);
        if ($column === '') {
            throw new InvalidArgumentException('PostgreSQL full-text source column must be non-empty.');
        }

        $this->parts[] = [
            'sql'    => 'coalesce(' . $this->quoteDottedIdentifier($column) . ", '')",
            'weight' => $this->normalizeWeight($weight),
        ];

        return $this;
    }

    public function raw(string|Stringable $expression, ?string $weight = null): self
    {
        $expression = trim((string) $expression);
        if ($expression === '') {
            throw new InvalidArgumentException('PostgreSQL full-text raw source expression must be non-empty.');
        }

        $this->parts[] = [
            'sql'    => $expression,
            'weight' => $this->normalizeWeight($weight),
        ];

        return $this;
    }

    public function toSql(): string
    {
        if ($this->parts === []) {
            return 'to_tsvector(' . $this->configSql() . ", '')";
        }

        $vectors = [];
        foreach ($this->parts as $part) {
            $vector = 'to_tsvector(' . $this->configSql() . ', ' . $part['sql'] . ')';
            if ($part['weight'] !== null) {
                $vector = 'setweight(' . $vector . ", '" . $part['weight'] . "')";
            }

            $vectors[] = $vector;
        }

        return implode(' || ', $vectors);
    }

    public function __toString(): string
    {
        return $this->toSql();
    }

    private function configSql(): string
    {
        return "'" . str_replace("'", "''", $this->config) . "'";
    }

    private function normalizeWeight(?string $weight): ?string
    {
        if ($weight === null) {
            return null;
        }

        $weight = trim($weight);
        if (preg_match('/^[A-D]$/', $weight) !== 1) {
            throw new InvalidArgumentException('PostgreSQL full-text weight must be one of A, B, C, D.');
        }

        return $weight;
    }

    private function quoteDottedIdentifier(string $identifier): string
    {
        $parts  = array_map('trim', explode('.', $identifier));
        $quoted = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $quoted[] = '"' . str_replace('"', '""', $part) . '"';
        }

        if ($quoted === []) {
            throw new InvalidArgumentException('PostgreSQL identifier must be non-empty.');
        }

        return implode('.', $quoted);
    }
}
