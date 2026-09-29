<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use PDO;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\QueryBuilder\AbstractQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function sort;

#[CoversClass(SelectQueryBuilder::class)]
#[CoversClass(AbstractQueryBuilder::class)]
#[CoversMethod(SelectQueryBuilder::class, 'union')]
#[CoversMethod(SelectQueryBuilder::class, 'whereInSubquery')]
#[CoversMethod(SelectQueryBuilder::class, 'fromSubquery')]
#[CoversMethod(SelectQueryBuilder::class, 'joinSubquery')]
#[CoversMethod(SelectQueryBuilder::class, 'selectExists')]
#[CoversMethod(SelectQueryBuilder::class, 'whereExists')]
final class SubqueryParamsIsolationTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(new PDO('sqlite::memory:'), new SqliteDriver());

        $this->connection->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, grp TEXT NOT NULL)');
        foreach ([1 => 'a', 2 => 'a', 3 => 'b', 4 => 'b', 5 => 'c', 6 => 'c'] as $id => $grp) {
            $this->connection->execute('INSERT INTO items (id, grp) VALUES (:id, :grp)', ['id' => $id, 'grp' => $grp]);
        }
    }

    /**
     * Проверим, что у частей UNION с одинаковыми автоматическими плейсхолдерами (whereIn) сохраняются свои значения.
     *
     * @see SelectQueryBuilder::union()
     */
    #[Test]
    public function unionKeepsParamsOfEachPart(): void
    {
        $second = $this->connection->query()->select('id')->from('items')->whereIn('id', [3, 4]);

        $rows = $this->connection->query()
            ->select('id')
            ->from('items')
            ->whereIn('id', [1, 2])
            ->union($second)
            ->fetchAll();

        self::assertSame([1, 2, 3, 4], $this->ids($rows));
    }

    /**
     * Проверим, что параметры WHERE IN (<подзапрос>) не подменяют параметры внешнего запроса.
     *
     * @see SelectQueryBuilder::whereInSubquery()
     */
    #[Test]
    public function whereInSubqueryKeepsOuterAndInnerParams(): void
    {
        $rows = $this->connection->query()
            ->select('id')
            ->from('items')
            ->whereIn('id', [1, 2, 3])
            ->whereInSubquery('id', static function (SelectQueryBuilder $q): void {
                $q->select('id')->from('items')->whereIn('id', [3, 4]);
            })
            ->fetchAll();

        self::assertSame([3], $this->ids($rows));
    }

    /**
     * Проверим, что параметры подзапроса во FROM не совпадают с параметрами внешнего WHERE.
     *
     * @see SelectQueryBuilder::fromSubquery()
     */
    #[Test]
    public function fromSubqueryKeepsOuterAndInnerParams(): void
    {
        $rows = $this->connection->query()
            ->select('t.id')
            ->fromSubquery(static function (SelectQueryBuilder $q): void {
                $q->select('id')->from('items')->whereIn('id', [1, 2, 3]);
            }, 't')
            ->whereIn('t.id', [2, 3, 4])
            ->fetchAll();

        self::assertSame([2, 3], $this->ids($rows));
    }

    /**
     * Проверим, что параметры подзапроса в JOIN не совпадают с параметрами внешнего WHERE.
     *
     * @see SelectQueryBuilder::joinSubquery()
     */
    #[Test]
    public function joinSubqueryKeepsOuterAndInnerParams(): void
    {
        $rows = $this->connection->query()
            ->select('i.id')
            ->from('items i')
            ->joinSubquery(static function (SelectQueryBuilder $q): void {
                $q->select('id')->from('items')->whereIn('id', [2, 3]);
            }, 's', 's.id = i.id')
            ->whereIn('i.id', [3, 4])
            ->fetchAll();

        self::assertSame([3], $this->ids($rows));
    }

    /**
     * Проверим, что параметры подзапроса в SELECT (EXISTS) не совпадают с параметрами внешнего WHERE.
     *
     * @see SelectQueryBuilder::selectExists()
     */
    #[Test]
    public function selectExistsKeepsOuterAndInnerParams(): void
    {
        $rows = $this->connection->query()
            ->select('i.id')
            ->selectExists(static function (SelectQueryBuilder $q): void {
                $q->select('1')->from('items x')->where('x.id = i.id')->whereIn('x.grp', ['b']);
            }, 'in_b')
            ->from('items i')
            ->whereIn('i.id', [2, 3])
            ->orderBy('i.id')
            ->fetchAll();

        self::assertSame([[2, 0], [3, 1]], array_map(
            static fn (array $row): array => [(int) $row['id'], (int) $row['in_b']],
            $rows,
        ));
    }

    /**
     * Проверим, что два подзапроса в одном запросе получают разные имена параметров.
     *
     * @see SelectQueryBuilder::whereExists()
     */
    #[Test]
    public function twoSubqueriesGetDifferentParamNames(): void
    {
        $rows = $this->connection->query()
            ->select('i.id')
            ->from('items i')
            ->whereExists(static function (SelectQueryBuilder $q): void {
                $q->select('1')->from('items x')->where('x.id = i.id')->whereIn('x.grp', ['a', 'b']);
            })
            ->whereExists(static function (SelectQueryBuilder $q): void {
                $q->select('1')->from('items y')->where('y.id = i.id')->whereIn('y.grp', ['b', 'c']);
            })
            ->fetchAll();

        self::assertSame([3, 4], $this->ids($rows));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        $ids = array_map('intval', array_column($rows, 'id'));
        sort($ids);

        return $ids;
    }
}
