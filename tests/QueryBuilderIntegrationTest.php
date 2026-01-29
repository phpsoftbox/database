<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use LogicException;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Tests\Utils\IntegrationDatabases;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_column;
use function array_map;

#[CoversNothing]
final class QueryBuilderIntegrationTest extends TestCase
{
    /**
     * Интеграционный тест: проверяет, что INSERT/SELECT/UPDATE/DELETE,
     * собранные QueryBuilder'ом, реально выполняются в MariaDB.
     */
    #[Test]
    public function queryBuilderCrudWorksInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runCrudScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет, что INSERT/SELECT/UPDATE/DELETE,
     * собранные QueryBuilder'ом, реально выполняются в Postgres.
     */
    #[Test]
    public function queryBuilderCrudWorksInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runCrudScenario($db, driver: 'postgres');
    }

    /**
     * Интеграционный тест: покрывает whereIn/orWhereIn/whereNotIn,
     * whereBetween/whereNotBetween и whereLike/whereNotLike на MariaDB.
     */
    #[Test]
    public function whereHelpersWorkInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runWhereHelpersScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: покрывает whereIn/orWhereIn/whereNotIn,
     * whereBetween/whereNotBetween и whereLike/whereNotLike на Postgres.
     */
    #[Test]
    public function whereHelpersWorkInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runWhereHelpersScenario($db, driver: 'postgres');
    }

    /**
     * Интеграционный тест: проверяет JOIN + GROUP BY + HAVING на MariaDB.
     */
    #[Test]
    public function joinGroupByHavingWorkInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runJoinGroupByHavingScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет JOIN + GROUP BY + HAVING на Postgres.
     */
    #[Test]
    public function joinGroupByHavingWorkInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runJoinGroupByHavingScenario($db, driver: 'postgres');
    }

    /**
     * Интеграционный тест: проверяет subqueries (IN subquery / EXISTS / FROM subquery / JOIN subquery)
     * на MariaDB.
     */
    #[Test]
    public function subqueriesWorkInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runSubqueriesScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет subqueries (IN subquery / EXISTS / FROM subquery / JOIN subquery)
     * на Postgres.
     */
    #[Test]
    public function subqueriesWorkInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runSubqueriesScenario($db, driver: 'postgres');
    }

    /**
     * Интеграционный тест: проверяет limit/offset на MariaDB.
     */
    #[Test]
    public function limitOffsetWorkInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runLimitOffsetScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет limit/offset на Postgres.
     */
    #[Test]
    public function limitOffsetWorkInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runLimitOffsetScenario($db, driver: 'postgres');
    }

    /**
     * Интеграционный тест: проверяет, что transaction() делает rollback при исключении (MariaDB).
     */
    #[Test]
    public function transactionRollbackWorksInMariaDb(): void
    {
        try {
            $db = IntegrationDatabases::mariadbDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runTransactionRollbackScenario($db, driver: 'mariadb');
    }

    /**
     * Интеграционный тест: проверяет, что transaction() делает rollback при исключении (Postgres).
     */
    #[Test]
    public function transactionRollbackWorksInPostgres(): void
    {
        try {
            $db = IntegrationDatabases::postgresDatabase();
        } catch (Throwable $e) {
            self::markTestSkipped($e->getMessage());
        }

        self::runTransactionRollbackScenario($db, driver: 'postgres');
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runCrudScenario(Database $db, string $driver): void
    {
        // Подготовка схемы.
        $db->execute('DROP TABLE IF EXISTS qb_items');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_items (id SERIAL PRIMARY KEY, name TEXT NOT NULL, price INT NOT NULL, active BOOLEAN NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_items (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, price INT NOT NULL, active TINYINT(1) NOT NULL)');
        }

        // INSERT
        $affected = $db->connection()->query()
            ->insert('qb_items', [
                'name'   => 'Apple',
                'price'  => 100,
                'active' => 1,
            ])
            ->execute();

        self::assertSame(1, $affected);

        // SELECT + WHERE
        $row = $db->connection()->query()
            ->select(['id', 'name', 'price', 'active'])
            ->from('qb_items')
            ->where('name = :name', ['name' => 'Apple'])
            ->fetchOne();

        self::assertNotNull($row);
        self::assertSame('Apple', $row['name']);

        // UPDATE
        $affected2 = $db->connection()->query()
            ->update('qb_items', ['price' => 200])
            ->where('name = :name', ['name' => 'Apple'])
            ->execute();

        self::assertSame(1, $affected2);

        $row2 = $db->connection()->query()
            ->select(['price'])
            ->from('qb_items')
            ->where('name = :name', ['name' => 'Apple'])
            ->fetchOne();

        self::assertNotNull($row2);
        self::assertSame(200, (int) $row2['price']);

        // DELETE
        $affected3 = $db->connection()->query()
            ->delete('qb_items')
            ->where('name = :name', ['name' => 'Apple'])
            ->execute();

        self::assertSame(1, $affected3);

        $row3 = $db->connection()->query()
            ->select()
            ->from('qb_items')
            ->where('name = :name', ['name' => 'Apple'])
            ->fetchOne();

        self::assertNull($row3);
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runWhereHelpersScenario(Database $db, string $driver): void
    {
        $db->execute('DROP TABLE IF EXISTS qb_where');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_where (id SERIAL PRIMARY KEY, name TEXT NOT NULL, age INT NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_where (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL, age INT NOT NULL)');
        }

        foreach ([
            ['name' => 'Alice', 'age' => 20],
            ['name' => 'Bob', 'age' => 30],
            ['name' => 'Charlie', 'age' => 40],
        ] as $row) {
            $db->connection()->query()->insert('qb_where', $row)->execute();
        }

        // whereIn
        $rowsIn = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereIn('name', ['Alice', 'Charlie'])
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Alice', 'Charlie'], array_column($rowsIn, 'name'));

        // whereIn(empty) => 1=0
        $rowsEmptyIn = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereIn('name', [])
            ->fetchAll();

        self::assertSame([], $rowsEmptyIn);

        // whereNotIn
        $rowsNotIn = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereNotIn('name', ['Alice'])
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Bob', 'Charlie'], array_column($rowsNotIn, 'name'));

        // whereNotIn(empty) => 1=1
        $rowsEmptyNotIn = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereNotIn('name', [])
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Alice', 'Bob', 'Charlie'], array_column($rowsEmptyNotIn, 'name'));

        // whereBetween
        $rowsBetween = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereBetween('age', 25, 35)
            ->fetchAll();

        self::assertSame(['Bob'], array_column($rowsBetween, 'name'));

        // whereNotBetween
        $rowsNotBetween = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereNotBetween('age', 25, 35)
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Alice', 'Charlie'], array_column($rowsNotBetween, 'name'));

        // whereLike
        $rowsLike = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereLike('name', 'A%')
            ->fetchAll();

        self::assertSame(['Alice'], array_column($rowsLike, 'name'));

        // whereNotLike
        $rowsNotLike = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->whereNotLike('name', 'A%')
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Bob', 'Charlie'], array_column($rowsNotLike, 'name'));

        // OR-группировка через callable (where + orWhere)
        $rowsGroup = $db->connection()->query()
            ->select(['name'])
            ->from('qb_where')
            ->where(function ($q): void {
                $q->where('name = :a', ['a' => 'Alice'])
                    ->orWhere('name = :c', ['c' => 'Charlie']);
            })
            ->orderBy('name', 'ASC')
            ->fetchAll();

        self::assertSame(['Alice', 'Charlie'], array_column($rowsGroup, 'name'));
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runJoinGroupByHavingScenario(Database $db, string $driver): void
    {
        $db->execute('DROP TABLE IF EXISTS qb_orders');
        $db->execute('DROP TABLE IF EXISTS qb_users');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_users (id SERIAL PRIMARY KEY, email TEXT NOT NULL)');
            $db->execute('CREATE TABLE qb_orders (id SERIAL PRIMARY KEY, user_id INT NOT NULL, total INT NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_users (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255) NOT NULL)');
            $db->execute('CREATE TABLE qb_orders (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, total INT NOT NULL)');
        }

        // users: 1,2
        $db->connection()->query()->insert('qb_users', ['email' => 'a@example.com'])->execute();
        $db->connection()->query()->insert('qb_users', ['email' => 'b@example.com'])->execute();

        // orders: user1 has 2, user2 has 1
        $db->connection()->query()->insert('qb_orders', ['user_id' => 1, 'total' => 10])->execute();
        $db->connection()->query()->insert('qb_orders', ['user_id' => 1, 'total' => 20])->execute();
        $db->connection()->query()->insert('qb_orders', ['user_id' => 2, 'total' => 30])->execute();

        $rows = $db->connection()->query()
            ->select(['u.id', 'COUNT(o.id) AS cnt', 'SUM(o.total) AS sum_total'])
            ->from('qb_users u')
            ->leftJoin('qb_orders o', 'o.user_id = u.id')
            ->groupBy('u.id')
            ->having('COUNT(o.id) >= :min', ['min' => 2])
            ->orderBy('u.id', 'ASC')
            ->fetchAll();

        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['id']);
        self::assertSame(2, (int) $rows[0]['cnt']);
        self::assertSame(30, (int) $rows[0]['sum_total']);
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runSubqueriesScenario(Database $db, string $driver): void
    {
        $db->execute('DROP TABLE IF EXISTS qb_sq_orders');
        $db->execute('DROP TABLE IF EXISTS qb_sq_users');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_sq_users (id SERIAL PRIMARY KEY, email TEXT NOT NULL)');
            $db->execute('CREATE TABLE qb_sq_orders (id SERIAL PRIMARY KEY, user_id INT NOT NULL, status TEXT NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_sq_users (id INT PRIMARY KEY AUTO_INCREMENT, email VARCHAR(255) NOT NULL)');
            $db->execute('CREATE TABLE qb_sq_orders (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, status VARCHAR(50) NOT NULL)');
        }

        $db->connection()->query()->insert('qb_sq_users', ['email' => 'a@example.com'])->execute();
        $db->connection()->query()->insert('qb_sq_users', ['email' => 'b@example.com'])->execute();

        $db->connection()->query()->insert('qb_sq_orders', ['user_id' => 1, 'status' => 'paid'])->execute();
        $db->connection()->query()->insert('qb_sq_orders', ['user_id' => 2, 'status' => 'new'])->execute();

        // WHERE IN (subquery)
        $rowsInSub = $db->connection()->query()
            ->select(['email'])
            ->from('qb_sq_users')
            ->whereInSubquery('id', function ($q): void {
                $q->select('user_id')
                    ->from('qb_sq_orders')
                    ->where('status = :st', ['st' => 'paid']);
            })
            ->fetchAll();

        self::assertSame(['a@example.com'], array_column($rowsInSub, 'email'));

        // SELECT EXISTS
        $rowExists = $db->connection()->query()
            ->select(['email'])
            ->selectExists(function ($q): void {
                $q->select('1')
                    ->from('qb_sq_orders o')
                    ->where('o.user_id = 1')
                    ->where('o.status = :st', ['st' => 'paid']);
            }, alias: 'has_paid')
            ->from('qb_sq_users')
            ->where('id = :id', ['id' => 1])
            ->fetchOne();

        self::assertNotNull($rowExists);
        self::assertSame('a@example.com', $rowExists['email']);
        self::assertTrue((bool) $rowExists['has_paid']);

        // FROM (subquery) AS ...
        $rowsFromSub = $db->connection()->query()
            ->select(['t.user_id', 't.cnt'])
            ->fromSubquery(function ($q): void {
                $q->select(['user_id', 'COUNT(*) AS cnt'])
                    ->from('qb_sq_orders')
                    ->groupBy('user_id');
            }, 't')
            ->orderBy('t.user_id', 'ASC')
            ->fetchAll();

        self::assertSame([1, 2], array_map('intval', array_column($rowsFromSub, 'user_id')));

        // JOIN (subquery)
        $rowsJoinSub = $db->connection()->query()
            ->select(['u.email', 't.cnt'])
            ->from('qb_sq_users u')
            ->joinSubquery(function ($q): void {
                $q->select(['user_id', 'COUNT(*) AS cnt'])
                    ->from('qb_sq_orders')
                    ->groupBy('user_id');
            }, 't', 't.user_id = u.id')
            ->orderBy('u.id', 'ASC')
            ->fetchAll();

        self::assertSame(['a@example.com', 'b@example.com'], array_column($rowsJoinSub, 'email'));
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runLimitOffsetScenario(Database $db, string $driver): void
    {
        // Просто чтобы не ругались инспекции на "unused".
        if ($driver === '') {
            throw new LogicException('Unreachable.');
        }

        $db->execute('DROP TABLE IF EXISTS qb_limit');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_limit (id SERIAL PRIMARY KEY, name TEXT NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_limit (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL)');
        }

        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $db->connection()->query()->insert('qb_limit', ['name' => $name])->execute();
        }

        $rows = $db->connection()->query()
            ->select(['name'])
            ->from('qb_limit')
            ->orderBy('id', 'ASC')
            ->limit(2)
            ->offset(1)
            ->fetchAll();

        self::assertSame(['B', 'C'], array_column($rows, 'name'));
    }

    /**
     * @param 'mariadb'|'postgres' $driver
     */
    private static function runTransactionRollbackScenario(Database $db, string $driver): void
    {
        // Просто чтобы не ругались инспекции на "unused".
        if ($driver === '') {
            throw new LogicException('Unreachable.');
        }

        $db->execute('DROP TABLE IF EXISTS qb_tx');

        if ($driver === 'postgres') {
            $db->execute('CREATE TABLE qb_tx (id SERIAL PRIMARY KEY, name TEXT NOT NULL)');
        } else {
            $db->execute('CREATE TABLE qb_tx (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255) NOT NULL)');
        }

        try {
            $db->connection()->transaction(function ($conn): void {
                $conn->query()->insert('qb_tx', ['name' => 'Alice'])->execute();

                throw new RuntimeException('boom');
            });
            self::fail('Exception was expected.');
        } catch (RuntimeException) {
            // ok
        }

        $row = $db->connection()->query()
            ->select(['COUNT(*) AS cnt'])
            ->from('qb_tx')
            ->fetchOne();

        self::assertNotNull($row);
        self::assertSame(0, (int) $row['cnt']);
    }
}
