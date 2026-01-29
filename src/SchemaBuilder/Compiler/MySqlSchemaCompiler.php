<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

final class MySqlSchemaCompiler extends AbstractMySqlSchemaCompiler
{
    protected function dialectName(): string
    {
        return 'mysql';
    }

    protected function supportsCreateIndexIfNotExists(): bool
    {
        return false;
    }
}
