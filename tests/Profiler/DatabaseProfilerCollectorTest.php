<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Profiler;

use InvalidArgumentException;
use PhpSoftBox\Database\Profiler\DatabaseProfilerCollector;
use PhpSoftBox\Profiler\ProfileTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseProfilerCollector::class)]
#[CoversMethod(DatabaseProfilerCollector::class, 'recordQuery')]
#[CoversMethod(DatabaseProfilerCollector::class, 'collect')]
#[CoversMethod(DatabaseProfilerCollector::class, 'reset')]
final class DatabaseProfilerCollectorTest extends TestCase
{
    /**
     * Проверим, что после предела список не растёт, счётчики продолжают считать, а сводка помечается truncated.
     *
     * @see DatabaseProfilerCollector::recordQuery()
     * @see DatabaseProfilerCollector::collect()
     */
    #[Test]
    public function itemsAboveLimitAreDroppedButCounted(): void
    {
        $collector = new DatabaseProfilerCollector(maxItems: 2);

        for ($index = 0; $index < 5; $index++) {
            $collector->recordQuery('default', 'sqlite', 'SELECT 1', [], durationMs: 1.0);
        }

        $data = $collector->collect($this->trace());

        self::assertCount(2, $data['queries']);
        self::assertSame(5, $data['summary']['queries']);
        self::assertSame(5.0, $data['summary']['total_ms']);
        self::assertTrue($data['summary']['truncated']);
        self::assertSame(3, $data['summary']['dropped']);
    }

    /**
     * Проверим, что reset() очищает и список, и признак обрезки.
     *
     * @see DatabaseProfilerCollector::reset()
     */
    #[Test]
    public function resetClearsItemsAndTruncation(): void
    {
        $collector = new DatabaseProfilerCollector(maxItems: 1);

        $collector->recordQuery('default', 'sqlite', 'SELECT 1', [], durationMs: 1.0);
        $collector->recordQuery('default', 'sqlite', 'SELECT 2', [], durationMs: 1.0);

        $collector->reset();
        $data = $collector->collect($this->trace());

        self::assertSame([], $data['queries']);
        self::assertSame(0, $data['summary']['queries']);
        self::assertFalse($data['summary']['truncated']);
        self::assertSame(0, $data['summary']['dropped']);
    }

    /**
     * Проверим, что отрицательный предел отклоняется.
     *
     * @see DatabaseProfilerCollector::recordQuery()
     */
    #[Test]
    public function rejectsNegativeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DatabaseProfilerCollector(maxItems: -1);
    }

    private function trace(): ProfileTrace
    {
        return new ProfileTrace('id', 'test', 'test');
    }
}
