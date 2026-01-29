<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

final class MySqlDriver extends AbstractMySqlDriver
{
    public function name(): string
    {
        return 'mysql';
    }

    protected function displayName(): string
    {
        return 'MySQL';
    }
}
