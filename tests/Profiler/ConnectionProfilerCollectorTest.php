<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Profiler;

use PDO;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Profiler\DatabaseProfilerCollector;
use PhpSoftBox\Profiler\NullProfiler;
use PhpSoftBox\Profiler\Profiler;
use PhpSoftBox\Profiler\ProfilerInterface;
use PhpSoftBox\Profiler\ProfileTrace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Connection::class)]
#[CoversMethod(Connection::class, 'fetchOne')]
final class ConnectionProfilerCollectorTest extends TestCase
{
    /**
     * Проверим, что при выключенном профайлере запросы в коллектор не попадают.
     *
     * @see Connection::fetchOne()
     */
    #[Test]
    public function disabledProfilerDoesNotRecordQueries(): void
    {
        $collector = new DatabaseProfilerCollector();

        $this->connection(new Profiler(enabled: false), $collector)->fetchOne('SELECT 1');

        self::assertSame(0, $this->summary($collector)['queries']);
    }

    /**
     * Проверим, что без профайлера (NullProfiler) запросы в коллектор не попадают.
     *
     * @see Connection::fetchOne()
     */
    #[Test]
    public function nullProfilerDoesNotRecordQueries(): void
    {
        $collector = new DatabaseProfilerCollector();

        $this->connection(new NullProfiler(), $collector)->fetchOne('SELECT 1');

        self::assertSame(0, $this->summary($collector)['queries']);
    }

    /**
     * Проверим, что внутри активной трассы запрос записывается в коллектор.
     *
     * @see Connection::fetchOne()
     */
    #[Test]
    public function activeTraceRecordsQueries(): void
    {
        $collector = new DatabaseProfilerCollector();
        $profiler  = new Profiler();

        $profiler->startTrace('test.request', 'http');

        $this->connection($profiler, $collector)->fetchOne('SELECT 1');

        self::assertSame(1, $this->summary($collector)['queries']);
    }

    private function connection(ProfilerInterface $profiler, DatabaseProfilerCollector $collector): Connection
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new Connection($pdo, new SqliteDriver(), profiler: $profiler, profilerCollector: $collector);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DatabaseProfilerCollector $collector): array
    {
        return $collector->collect(new ProfileTrace('id', 'test', 'test'))['summary'];
    }
}
