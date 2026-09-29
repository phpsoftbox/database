<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Connection;

use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Connection::class)]
#[CoversMethod(Connection::class, 'execute')]
#[CoversMethod(Connection::class, 'fetchAll')]
final class PostgresBooleanParamsTest extends TestCase
{
    private ConnectionInterface $connection;

    protected function setUp(): void
    {
        try {
            $this->connection = IntegrationDatabases::postgresDatabase()->connection();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        $this->connection->execute('DROP TABLE IF EXISTS psb_bool_params');
        $this->connection->execute('CREATE TABLE psb_bool_params (id INTEGER PRIMARY KEY, active BOOLEAN NOT NULL)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->execute('DROP TABLE IF EXISTS psb_bool_params');
        }
    }

    /**
     * Проверим, что false вставляется в boolean-колонку PostgreSQL через QueryBuilder (раньше связывался как '' и падал с 22P02).
     *
     * @see Connection::execute()
     */
    #[Test]
    public function insertsFalseIntoBooleanColumn(): void
    {
        $this->connection->query()->insert('psb_bool_params', ['id' => 1, 'active' => false])->execute();

        $row = $this->connection->fetchOne('SELECT active FROM psb_bool_params WHERE id = 1');

        self::assertFalse($row['active']);
    }

    /**
     * Проверим, что false работает как параметр условия WHERE в PostgreSQL.
     *
     * @see Connection::fetchAll()
     */
    #[Test]
    public function filtersByFalseParam(): void
    {
        $this->connection->execute('INSERT INTO psb_bool_params (id, active) VALUES (1, TRUE), (2, FALSE)');

        $rows = $this->connection->query()->select('id')->from('psb_bool_params')->where(['active' => false])->fetchAll();

        self::assertSame([['id' => 2]], $rows);
    }
}
