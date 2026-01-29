<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\DeleteQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\InsertQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\UpdateQueryBuilder;

interface QueryCompilerInterface
{
    /** @return array{sql: string, params: array<string|int, mixed>} */
    public function compileSelect(SelectQueryBuilder $builder): array;

    /** @return array{sql: string, params: array<string|int, mixed>} */
    public function compileInsert(InsertQueryBuilder $builder): array;

    /** @return array{sql: string, params: array<string|int, mixed>} */
    public function compileUpdate(UpdateQueryBuilder $builder): array;

    /** @return array{sql: string, params: array<string|int, mixed>} */
    public function compileDelete(DeleteQueryBuilder $builder): array;
}
