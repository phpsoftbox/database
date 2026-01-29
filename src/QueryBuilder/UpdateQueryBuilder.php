<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

final class UpdateQueryBuilder extends AbstractQueryBuilder
{
    use WhereAwareTrait;

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
    public function set(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toSql(): array
    {
        return $this->connection->driver()->createQueryCompiler()->compileUpdate($this);
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
        $built = $this->toSql();

        return $this->connection->execute($built['sql'], $built['params']);
    }
}
