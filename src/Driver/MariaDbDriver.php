<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

final class MariaDbDriver extends AbstractMySqlDriver
{
    public function name(): string
    {
        return 'mariadb';
    }

    protected function displayName(): string
    {
        return 'MariaDB';
    }
}
