<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Validator;

use Closure;
use PhpSoftBox\Validator\Db\Contracts\ExistingValuesQueryInterface;

final readonly class DatabaseExistingValuesQuery implements ExistingValuesQueryInterface
{
    /**
     * @param Closure():list<mixed> $fetch
     * @param Closure():list<mixed> $fetchWarmup
     */
    public function __construct(
        private Closure $fetch,
        private Closure $fetchWarmup,
        private bool $shouldWarmup = false,
    ) {
    }

    public function warmup(): self
    {
        return new self(
            $this->fetch,
            $this->fetchWarmup,
            shouldWarmup: true,
        );
    }

    public function fetch(): array
    {
        if ($this->shouldWarmup) {
            return ($this->fetchWarmup)();
        }

        return ($this->fetch)();
    }
}
