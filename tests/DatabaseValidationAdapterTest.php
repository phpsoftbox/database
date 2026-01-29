<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Contracts\WarmupAwareConnectionInterface;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PhpSoftBox\Database\Validator\DatabaseExistingValuesQuery;
use PhpSoftBox\Database\Validator\DatabaseValidationAdapter;
use PhpSoftBox\Database\Warmup\WarmupLookup;
use PhpSoftBox\Database\Warmup\WarmupStore;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseValidationAdapter::class)]
#[CoversClass(DatabaseExistingValuesQuery::class)]
final class DatabaseValidationAdapterTest extends TestCase
{
    /**
     * Проверяет, что bulk-проверка значений выполняется одним SELECT с WHERE IN.
     */
    #[Test]
    public function existingValuesUsesSingleWhereInQuery(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [
                    ['product_id' => 10],
                    ['product_id' => 20],
                ];
            }
        };

        $manager = new class ($connection) implements ConnectionManagerInterface {
            public ?string $lastConnectionName = null;

            public function __construct(
                private readonly ConnectionInterface $connection,
            ) {
            }

            public function connection(string $name = 'default'): ConnectionInterface
            {
                $this->lastConnectionName = $name;

                return $this->connection;
            }

            public function read(string $name = 'default'): ConnectionInterface
            {
                return $this->connection($name . '.read');
            }

            public function write(string $name = 'default'): ConnectionInterface
            {
                return $this->connection($name . '.write');
            }
        };

        $adapter = new DatabaseValidationAdapter($manager);

        $found = $adapter->existingValues(
            LookupSpec::forTable('shipment_products')
                ->lookupColumn('product_id')
                ->values([10, 20, 30])
                ->where('shipment_id', 123),
            'tenant',
        )->fetch();

        self::assertSame([10, 20], $found);
        self::assertSame('tenant', $manager->lastConnectionName);
        self::assertCount(1, $connection->executed);
        self::assertSame(
            'SELECT "product_id" FROM "shipment_products" WHERE ("shipment_id" = :_p1) AND ("product_id" IN (:in_1, :in_2, :in_3))',
            $connection->executed[0]['sql'],
        );
        self::assertSame(
            ['_p1' => 123, 'in_1' => 10, 'in_2' => 20, 'in_3' => 30],
            $connection->executed[0]['params'],
        );
    }

    /**
     * Проверяет, что пустой список не делает запрос.
     */
    #[Test]
    public function existingValuesDoesNotQueryEmptyValues(): void
    {
        $connection = new SpyConnection(new FakePdo('sqlite'));

        $manager = new class ($connection) implements ConnectionManagerInterface {
            public function __construct(
                private readonly ConnectionInterface $connection,
            ) {
            }

            public function connection(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }

            public function read(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }

            public function write(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }
        };

        $adapter = new DatabaseValidationAdapter($manager);

        self::assertSame([], $adapter->existingValues(
            LookupSpec::forTable('products')->lookupColumn('id')->values([]),
        )->fetch());
        self::assertSame([], $connection->executed);
    }

    /**
     * Проверяет, что warmup bulk-проверка прогревает rows и повторно не ходит в БД.
     */
    #[Test]
    public function existingValuesWarmupWarmsRows(): void
    {
        $connection = new class (new FakePdo('sqlite')) extends SpyConnection implements WarmupAwareConnectionInterface {
            private WarmupStore $warmupStore;

            private ?WarmupLookup $warmup = null;

            public function __construct(FakePdo $pdo)
            {
                parent::__construct($pdo);

                $this->warmupStore = new WarmupStore();
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [
                    ['shipment_id' => 123, 'product_id' => 10, 'qty' => 1],
                    ['shipment_id' => 123, 'product_id' => 20, 'qty' => 2],
                ];
            }

            public function warmup(): WarmupLookup
            {
                return $this->warmup ??= new WarmupLookup($this, $this->warmupStore, 'tenant');
            }

            public function clearWarmup(): void
            {
                $this->warmupStore->clear();
            }
        };

        $manager = new readonly class ($connection) implements ConnectionManagerInterface {
            public function __construct(
                private ConnectionInterface $connection,
            ) {
            }

            public function connection(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }

            public function read(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }

            public function write(string $name = 'default'): ConnectionInterface
            {
                return $this->connection;
            }
        };

        $adapter = new DatabaseValidationAdapter($manager);
        $lookup  = LookupSpec::forTable('shipment_products')
            ->lookupColumn('product_id')
            ->values([10, 20, 30])
            ->where('shipment_id', 123);

        $first  = $adapter->existingValues($lookup, 'tenant')->warmup()->fetch();
        $second = $adapter->existingValues($lookup, 'tenant')->warmup()->fetch();

        self::assertSame([10, 20], $first);
        self::assertSame([10, 20], $second);
        self::assertCount(1, $connection->executed);
        self::assertSame(
            'SELECT * FROM "shipment_products" WHERE ("shipment_id" = :_p1) AND ("product_id" IN (:in_1, :in_2, :in_3))',
            $connection->executed[0]['sql'],
        );
    }

}
