<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Connection;

/**
 * Менеджер подключений, который сбрасывает warmup store уже созданных подключений.
 */
interface WarmupAwareConnectionManagerInterface extends ConnectionManagerInterface
{
    public function clearWarmup(): void;
}
