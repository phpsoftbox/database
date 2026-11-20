<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Seed;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

interface SeederInterface
{
    public function run(ConnectionInterface $connection): void;
}
