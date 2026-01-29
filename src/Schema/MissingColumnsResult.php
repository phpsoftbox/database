<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;
use function trim;

/**
 * Результат проверки отсутствующих колонок.
 *
 * @implements IteratorAggregate<int, string>
 */
final class MissingColumnsResult implements Countable, IteratorAggregate
{
    /**
     * @var list<string>
     */
    private array $columns;

    /**
     * @var array<string, true>
     */
    private array $lookup = [];

    /**
     * @param list<string> $columns
     */
    public function __construct(array $columns)
    {
        $this->columns = [];

        foreach ($columns as $column) {
            $column = trim($column);
            if ($column === '' || isset($this->lookup[$column])) {
                continue;
            }

            $this->columns[]       = $column;
            $this->lookup[$column] = true;
        }
    }

    /**
     * @param list<string> $columns
     */
    public static function from(array $columns): self
    {
        return new self($columns);
    }

    public function has(string $column): bool
    {
        $column = trim($column);

        return $column !== '' && isset($this->lookup[$column]);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->columns !== [];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    public function count(): int
    {
        return count($this->columns);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->columns);
    }
}
