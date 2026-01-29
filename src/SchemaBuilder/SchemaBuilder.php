<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SchemaCompilerInterface;

final readonly class SchemaBuilder implements SchemaBuilderInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private SchemaCompilerInterface $compiler,
    ) {
    }

    public function create(string $table, callable $definition, bool $ifNotExists = true): void
    {
        $blueprint = new TableBlueprint($this->connection->table($table));

        $definition($blueprint);

        $sql = $ifNotExists
            ? $this->compiler->compileCreateTableIfNotExists($blueprint)
            : $this->compiler->compileCreateTable($blueprint);

        $this->connection->execute($sql);

        foreach ($this->compiler->compileCreateIndexes($blueprint) as $indexSql) {
            $this->connection->execute($indexSql);
        }
    }

    public function createIfNotExists(string $table, callable $definition): void
    {
        $this->create($table, $definition, true);
    }

    public function alterTable(string $table, callable $definition): void
    {
        $blueprint = new TableBlueprint($this->connection->table($table));

        $definition($blueprint);

        $sqlStatements = $this->compiler->compileAlterTableAddColumns($blueprint);

        foreach ($sqlStatements as $sql) {
            $this->connection->execute($sql);
        }
    }

    public function addColumn(string $table, callable $definition): void
    {
        $this->alterTable($table, $definition);
    }

    public function renameTable(string $from, string $to): void
    {
        $sql = $this->compiler->compileRenameTable(
            $this->connection->table($from),
            $this->connection->table($to),
        );
        $this->connection->execute($sql);
    }

    public function dropIfExists(string $table): void
    {
        $sql = $this->compiler->compileDropIfExists($this->connection->table($table));
        $this->connection->execute($sql);
    }

    public function drop(string $table): void
    {
        $sql = $this->compiler->compileDropTable($this->connection->table($table));
        $this->connection->execute($sql);
    }
}
