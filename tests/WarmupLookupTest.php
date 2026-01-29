<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use InvalidArgumentException;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PhpSoftBox\Database\Warmup\WarmupEntry;
use PhpSoftBox\Database\Warmup\WarmupLookup;
use PhpSoftBox\Database\Warmup\WarmupReadMode;
use PhpSoftBox\Database\Warmup\WarmupStore;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[CoversClass(WarmupEntry::class)]
#[CoversClass(WarmupLookup::class)]
final class WarmupLookupTest extends TestCase
{
    /**
     * Проверяет, что warmup одним запросом дозагружает miss-значения и повторно использует прогретые строки.
     */
    #[Test]
    public function manyUniqueFetchesMissesOnceAndReusesWarmRows(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            /** @var array<int, array<string, mixed>> */
            public array $rowsById = [
                1 => ['id' => 1, 'name' => 'A'],
                2 => ['id' => 2, 'name' => 'B'],
            ];

            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                $rows = [];
                foreach ($params as $value) {
                    if (isset($this->rowsById[$value])) {
                        $rows[] = $this->rowsById[$value];
                    }
                }

                return $rows;
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('users')->lookupColumn('id')->values([1, 2, 3]);

        self::assertSame([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ], $lookup->manyUnique($spec));
        self::assertSame([
            ['id' => 1, 'name' => 'A'],
            ['id' => 2, 'name' => 'B'],
        ], $lookup->manyUnique($spec));

        self::assertCount(1, $connection->executed);
        self::assertSame(
            'SELECT * FROM "users" WHERE ("id" IN (:in_1, :in_2, :in_3))',
            $connection->executed[0]['sql'],
        );
    }

    /**
     * Проверяет, что grouped warmup хранит список строк на один lookup key и не теряет one-to-many записи.
     */
    #[Test]
    public function manyGroupedStoresMultipleRowsPerLookupValue(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [
                    ['shipment_id' => 123, 'product_id' => 10],
                    ['shipment_id' => 123, 'product_id' => 20],
                    ['shipment_id' => 456, 'product_id' => 30],
                ];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('shipment_products')
            ->lookupColumn('shipment_id')
            ->values([123, 456]);

        self::assertSame([
            ['shipment_id' => 123, 'product_id' => 10],
            ['shipment_id' => 123, 'product_id' => 20],
            ['shipment_id' => 456, 'product_id' => 30],
        ], $lookup->manyGrouped($spec));
        self::assertSame([
            ['shipment_id' => 123, 'product_id' => 10],
            ['shipment_id' => 123, 'product_id' => 20],
            ['shipment_id' => 456, 'product_id' => 30],
        ], $lookup->manyGrouped($spec));

        self::assertCount(1, $connection->executed);
    }

    /**
     * Проверяет, что unique warmup явно запрещает несколько строк на один lookup key.
     */
    #[Test]
    public function manyUniqueThrowsWhenLookupReturnsDuplicateRowsForValue(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [
                    ['shipment_id' => 123, 'product_id' => 10],
                    ['shipment_id' => 123, 'product_id' => 20],
                ];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('shipment_products')
            ->lookupColumn('shipment_id')
            ->values([123]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Warmup unique lookup returned multiple rows');

        $lookup->manyUnique($spec);
    }

    /**
     * Проверяет, что одиночное чтение использует уже прогретую строку без повторного запроса.
     */
    #[Test]
    public function oneUsesWarmRows(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [['id' => 7, 'name' => 'Seven']];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('users')->lookupColumn('id')->value(7);

        self::assertSame(['id' => 7, 'name' => 'Seven'], $lookup->one($spec));
        self::assertSame(['id' => 7, 'name' => 'Seven'], $lookup->one($spec));
        self::assertCount(1, $connection->executed);
    }

    /**
     * Проверяет, что режим Fresh принудительно перечитывает строку из БД и обновляет warmup.
     */
    #[Test]
    public function freshRefreshesWarmRows(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            /** @var array<int, array<string, mixed>> */
            public array $rowsById = [
                1 => ['id' => 1, 'name' => 'Old'],
            ];

            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [$this->rowsById[1]];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('users')->lookupColumn('id')->value(1);

        self::assertSame(['id' => 1, 'name' => 'Old'], $lookup->one($spec));
        $connection->rowsById[1] = ['id' => 1, 'name' => 'Fresh'];

        self::assertSame(['id' => 1, 'name' => 'Old'], $lookup->one($spec));
        self::assertSame(['id' => 1, 'name' => 'Fresh'], $lookup->one($spec, WarmupReadMode::Fresh));
        self::assertSame(['id' => 1, 'name' => 'Fresh'], $lookup->one($spec));
        self::assertCount(2, $connection->executed);
    }

    /**
     * Проверяет, что режим Bypass не читает и не записывает warmup entries.
     */
    #[Test]
    public function bypassDoesNotReadOrWriteWarmRows(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            /** @var array<int, array<string, mixed>> */
            public array $rowsById = [
                1 => ['id' => 1, 'name' => 'First'],
            ];

            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [$this->rowsById[1]];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('users')->lookupColumn('id')->value(1);

        self::assertSame(['id' => 1, 'name' => 'First'], $lookup->one($spec, WarmupReadMode::Bypass));
        $connection->rowsById[1] = ['id' => 1, 'name' => 'Second'];

        self::assertSame(['id' => 1, 'name' => 'Second'], $lookup->one($spec));
        self::assertCount(2, $connection->executed);
    }

    /**
     * Проверяет, что scoped warmup с fixed criteria строит ключ из criteria и lookup column.
     */
    #[Test]
    public function criteriaUseDefaultWarmupKeyColumns(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [['shipment_id' => 123, 'product_id' => 10]];
            }
        };

        $lookup = new WarmupLookup($connection, new WarmupStore(), 'main');
        $spec   = LookupSpec::forTable('shipment_products')
            ->lookupColumn('product_id')
            ->values([10])
            ->where('shipment_id', 123);

        self::assertSame([['shipment_id' => 123, 'product_id' => 10]], $lookup->manyUnique($spec));
        self::assertSame([['shipment_id' => 123, 'product_id' => 10]], $lookup->manyUnique($spec));

        self::assertCount(1, $connection->executed);
        self::assertSame(
            'SELECT * FROM "shipment_products" WHERE ("shipment_id" = :_p1) AND ("product_id" IN (:in_1))',
            $connection->executed[0]['sql'],
        );
    }

    /**
     * Проверяет, что явный warmup key покрывает все criteria columns, а не только lookup-column.
     */
    #[Test]
    public function warmupKeyColumnsMustIncludeCriteriaColumns(): void
    {
        $lookup = new WarmupLookup(new SpyConnection(new FakePdo('sqlite')), new WarmupStore(), 'main');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lookup key columns must include criteria column "status".');

        $lookup->manyUnique(
            LookupSpec::forTable('shipment_products')
                ->lookupColumn('product_id')
                ->values([10])
                ->whereAll(['shipment_id' => 123, 'status' => 'active'])
                ->keyColumns('shipment_id', 'product_id'),
        );
    }
}
