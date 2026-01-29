<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

enum WarmupReadMode
{
    /**
     * Read warmed rows first, fetch misses from DB and remember the result.
     */
    case Use;

    /**
     * Fetch from DB and refresh warmup entries.
     */
    case Fresh;

    /**
     * Fetch from DB without reading or writing warmup entries.
     */
    case Bypass;
}
