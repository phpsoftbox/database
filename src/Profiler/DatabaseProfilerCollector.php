<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Profiler;

use InvalidArgumentException;
use PhpSoftBox\Profiler\ProfilerCollectorInterface;
use PhpSoftBox\Profiler\ProfileTrace;

use function array_values;
use function count;
use function round;

final class DatabaseProfilerCollector implements ProfilerCollectorInterface
{
    public const int DEFAULT_MAX_ITEMS = 5000;

    private int $queries   = 0;
    private int $errors    = 0;
    private float $totalMs = 0.0;

    /**
     * @var list<array<string, mixed>>
     */
    private array $items = [];

    private int $droppedItems = 0;

    /**
     * @param int $maxItems предел числа запросов в списке; после него растут только счётчики
     */
    public function __construct(
        private readonly int $maxItems = self::DEFAULT_MAX_ITEMS,
    ) {
        if ($maxItems < 0) {
            throw new InvalidArgumentException('Max items must not be negative.');
        }
    }

    public function key(): string
    {
        return 'database';
    }

    /**
     * @param array<string|int, mixed> $bindings
     */
    public function recordQuery(
        string $connection,
        string $driver,
        string $sql,
        array $bindings,
        float $durationMs,
        ?int $rowCount = null,
        bool $failed = false,
        ?string $exceptionClass = null,
    ): void {
        $this->queries++;
        $this->totalMs = round($this->totalMs + $durationMs, 3);

        if ($failed) {
            $this->errors++;
        }

        if (count($this->items) >= $this->maxItems) {
            $this->droppedItems++;

            return;
        }

        $this->items[] = [
            'connection'      => $connection,
            'driver'          => $driver,
            'sql'             => $sql,
            'params_count'    => count($bindings),
            'duration_ms'     => round($durationMs, 3),
            'row_count'       => $rowCount,
            'failed'          => $failed,
            'exception_class' => $exceptionClass,
        ];
    }

    public function collect(ProfileTrace $trace): array
    {
        return [
            'summary' => [
                'queries'   => $this->queries,
                'errors'    => $this->errors,
                'total_ms'  => round($this->totalMs, 3),
                'truncated' => $this->droppedItems > 0,
                'dropped'   => $this->droppedItems,
            ],
            'queries' => array_values($this->items),
        ];
    }

    public function reset(): void
    {
        $this->queries = 0;
        $this->errors  = 0;
        $this->totalMs = 0.0;
        $this->items   = [];

        $this->droppedItems = 0;
    }
}
