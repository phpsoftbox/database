<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

final class DeleteQueryBuilder extends AbstractQueryBuilder
{
    use WhereAwareTrait;

    protected int $paramCounter = 0;

    private string $table;

    public function __construct(
        ConnectionInterface $connection,
        string $table,
    ) {
        parent::__construct($connection);
        $this->table = $this->applyTablePrefix($table);
    }

    /**
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    public function toSql(): array
    {
        return $this->connection->driver()->createQueryCompiler()->compileDelete($this);
    }

    /** @internal */
    public function table(): string
    {
        return $this->table;
    }

    public function execute(): int
    {
        $built = $this->toSql();

        return $this->connection->execute($built['sql'], $built['params']);
    }
}
