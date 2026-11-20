<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Seed;

enum SeedTarget: string
{
    case Core   = 'core';
    case Tenant = 'tenant';
}
