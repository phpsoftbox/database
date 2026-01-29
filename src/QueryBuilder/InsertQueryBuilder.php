<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

final class InsertQueryBuilder extends AbstractQueryBuilder
{
    protected int $paramCounter = 0;

    private string $table;

    /**
     * @var array<string, mixed>
     */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        ConnectionInterface $connection,
        string $table,
        array $data = [],
    ) {
        parent::__construct($connection);
        $this->table = $this->applyTablePrefix($table);
        $this->data  = $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function values(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function compile(): CompiledQuery
    {
        return $this->connection->driver()->createQueryCompiler()->compileInsert($this);
    }

    /**
     * Legacy API.
     *
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toSql(): array
    {
        return $this->compile()->toArray();
    }

    /** @internal */
    public function table(): string
    {
        return $this->table;
    }

    /**
     * @internal
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function execute(): int
    {
        $built = $this->compile();

        return $this->connection->execute($built->sql, $built->bindings);
    }
}
