<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Contracts;

use PhpSoftBox\Database\Warmup\WarmupLookup;

interface WarmupAwareConnectionInterface extends ConnectionInterface
{
    public function warmup(): WarmupLookup;

    public function clearWarmup(): void;
}
