<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\SchemaBuilder\Compiler\SchemaCompilerInterface;

use function array_merge;

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

        $sqlList = array_merge(
            $this->compiler->compileAlterTableDropForeignKeys($blueprint),
            $this->compiler->compileDropIndexes($blueprint),
            $this->compiler->compileAlterTableRenameColumns($blueprint),
            $this->compiler->compileAlterTableDropColumns($blueprint),
            $this->compiler->compileAlterTableAddColumns($blueprint),
            $this->compiler->compileAlterTableChangeColumns($blueprint),
            $this->compiler->compileCreateIndexes($blueprint),
            $this->compiler->compileAlterTableAddForeignKeys($blueprint),
        );

        foreach ($sqlList as $sql) {
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

    public function createExtensionIfNotExists(string $extension): void
    {
        $this->connection->execute($this->compiler->compileCreateExtensionIfNotExists($extension));
    }

    public function dropExtensionIfExists(string $extension): void
    {
        $this->connection->execute($this->compiler->compileDropExtensionIfExists($extension));
    }
}
