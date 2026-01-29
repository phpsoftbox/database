<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

final class MariaDbSchemaCompiler extends AbstractMySqlSchemaCompiler
{
    protected function dialectName(): string
    {
        return 'mariadb';
    }

    protected function supportsGeneratedColumnNullability(): bool
    {
        return false;
    }
}
