<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\QueryBuilder\Paginator;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Paginator::class)]
final class PaginatorTest extends TestCase
{
    /**
     * Проверяет, что пагинатор нормализует perPage (минимум 1).
     */
    #[Test]
    public function normalizesPerPage(): void
    {
        $p = new Paginator(0);

        self::assertSame(1, $p->perPage());
    }

    /**
     * Проверяет, что Paginator::paginate проксирует вызов в SelectQueryBuilder::paginate,
     * используя perPage из настроек пагинатора.
     */
    #[Test]
    public function paginateDelegatesToBuilder(): void
    {
        $conn = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [['id' => 1]];
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                parent::fetchOne($sql, $params);

                return ['__agg' => '1'];
            }
        };

        $builder = $conn->query()->select()->from('users')->orderBy('id', 'ASC');

        $result = new Paginator(10)->paginate($builder, page: 1);

        self::assertSame(1, $result['total']);
        self::assertSame(1, $result['page']);
        self::assertSame(10, $result['perPage']);
        self::assertSame(1, $result['pages']);
        self::assertSame([['id' => 1]], $result['items']);

        // 1) COUNT
        self::assertSame('SELECT COUNT(*) AS __agg FROM "users"', $conn->executed[0]['sql']);
        // 2) ITEMS
        self::assertSame('SELECT * FROM "users" ORDER BY "id" ASC LIMIT 10 OFFSET 0', $conn->executed[1]['sql']);
    }
}
