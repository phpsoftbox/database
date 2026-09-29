<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use PDO;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SelectQueryBuilder::class)]
#[CoversMethod(SelectQueryBuilder::class, 'count')]
#[CoversMethod(SelectQueryBuilder::class, 'paginate')]
final class SelectCountTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(new PDO('sqlite::memory:'), new SqliteDriver());

        $this->connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, grp TEXT NOT NULL)');
        foreach ([1 => 'a', 2 => 'a', 3 => 'b', 4 => 'b', 5 => 'c'] as $id => $grp) {
            $this->connection->execute('INSERT INTO items (id, grp) VALUES (:id, :grp)', ['id' => $id, 'grp' => $grp]);
        }
    }

    /**
     * Проверим, что count() без группировок считает строки таблицы через COUNT(*).
     *
     * @see SelectQueryBuilder::count()
     */
    #[Test]
    public function countsPlainQuery(): void
    {
        $count = $this->connection->query()->select()->from('items')->where('id > :id', ['id' => 1])->count();

        self::assertSame(4, $count);
    }

    /**
     * Проверим, что count() с GROUP BY возвращает количество групп, а не размер первой группы.
     *
     * @see SelectQueryBuilder::count()
     */
    #[Test]
    public function countsGroupsForGroupBy(): void
    {
        $count = $this->connection->query()->select('grp')->from('items')->groupBy('grp')->count();

        self::assertSame(3, $count);
    }

    /**
     * Проверим, что count() с DISTINCT возвращает количество уникальных строк.
     *
     * @see SelectQueryBuilder::count()
     */
    #[Test]
    public function countsDistinctRows(): void
    {
        $count = $this->connection->query()->select('grp')->distinct()->from('items')->count();

        self::assertSame(3, $count);
    }

    /**
     * Проверим, что count() с UNION считает строки всего объединения с учётом параметров обеих частей.
     *
     * @see SelectQueryBuilder::count()
     */
    #[Test]
    public function countsUnionRows(): void
    {
        $second = $this->connection->query()->select('id')->from('items')->whereIn('id', [4, 5]);

        $count = $this->connection->query()
            ->select('id')
            ->from('items')
            ->whereIn('id', [1, 2])
            ->unionAll($second)
            ->orderBy('id')
            ->limit(1)
            ->count();

        self::assertSame(4, $count);
    }

    /**
     * Проверим, что paginate() с GROUP BY возвращает total по количеству групп.
     *
     * @see SelectQueryBuilder::paginate()
     */
    #[Test]
    public function paginateUsesGroupCountAsTotal(): void
    {
        $result = $this->connection->query()
            ->select('grp')
            ->selectCount('*', 'cnt')
            ->from('items')
            ->groupBy('grp')
            ->orderBy('grp')
            ->paginate(1, 2);

        self::assertSame(3, $result->meta()['total']);
        self::assertCount(2, $result->data());
    }
}
