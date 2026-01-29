<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Compiler;

use PhpSoftBox\Database\QueryBuilder\CompiledQuery;
use PhpSoftBox\Database\QueryBuilder\DeleteQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\InsertQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\UpdateQueryBuilder;

interface QueryCompilerInterface
{
    public function compileSelect(SelectQueryBuilder $builder): CompiledQuery;

    public function compileInsert(InsertQueryBuilder $builder): CompiledQuery;

    public function compileUpdate(UpdateQueryBuilder $builder): CompiledQuery;

    public function compileDelete(DeleteQueryBuilder $builder): CompiledQuery;
}
