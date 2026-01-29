<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Profiler;

use PhpSoftBox\Profiler\ProfilerExtensionInterface;
use PhpSoftBox\Profiler\ProfilerRegistryInterface;

final class DatabaseProfilerExtension implements ProfilerExtensionInterface
{
    private DatabaseProfilerCollector $collector;

    public function __construct(?DatabaseProfilerCollector $collector = null)
    {
        $this->collector = $collector ?? new DatabaseProfilerCollector();
    }

    public function collector(): DatabaseProfilerCollector
    {
        return $this->collector;
    }

    public function register(ProfilerRegistryInterface $registry): void
    {
        $registry->addCollector($this->collector);
    }
}
