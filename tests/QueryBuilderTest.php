<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\QueryBuilder\DeleteQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\InsertQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Database\QueryBuilder\UpdateQueryBuilder;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueryFactory::class)]
#[CoversClass(SelectQueryBuilder::class)]
#[CoversClass(InsertQueryBuilder::class)]
#[CoversClass(UpdateQueryBuilder::class)]
#[CoversClass(DeleteQueryBuilder::class)]
final class QueryBuilderTest extends TestCase
{
    /**
     * Проверяет, что SelectQueryBuilder собирает SELECT + FROM и автоматически применяет префикс таблиц.
     */
    #[Test]
    public function buildsSelectFromSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $qb = $conn->query()
            ->select(['id', 'name'])
            ->from('users');

        $built = $qb->toSql();

        self::assertSame('SELECT "id", "name" FROM "t_users"', $built['sql']);
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет, что raw-Expression в from() не применяет префикс.
     */
    #[Test]
    public function fromExpressionDoesNotApplyPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $raw = $conn->query()->raw('users u');

        $built = $conn->query()
            ->select()
            ->from($raw)
            ->toSql();

        self::assertSame('SELECT * FROM users u', $built['sql']);
    }

    /**
     * Проверяет, что where() объединяются через AND и параметры мерджатся.
     */
    #[Test]
    public function buildsWhereAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('id = :id', ['id' => 10])
            ->where('name = :name', ['name' => 'Alice'])
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("id" = :id) AND ("name" = :name)', $built['sql']);
        self::assertSame(['id' => 10, 'name' => 'Alice'], $built['params']);
    }

    /**
     * Проверяет, что fetchAll() вызывает Connection::fetchAll().
     */
    #[Test]
    public function fetchAllExecutesOnConnection(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $conn->query()
            ->select()
            ->from('users')
            ->where('id = :id', ['id' => 1])
            ->fetchAll();

        self::assertCount(1, $conn->executed);
        self::assertSame('SELECT * FROM "users" WHERE ("id" = :id)', $conn->executed[0]['sql']);
        self::assertSame(['id' => 1], $conn->executed[0]['params']);
    }

    /**
     * Проверяет, что join() добавляет INNER JOIN и применяет префикс для joined-таблицы.
     */
    #[Test]
    public function joinAppliesPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->select()
            ->from('users')
            ->join('clients', 'clients.user_id = users.id')
            ->toSql();

        self::assertSame('SELECT * FROM "t_users" INNER JOIN "t_clients" ON "clients"."user_id" = "users"."id"', $built['sql']);
    }

    /**
     * Проверяет, что join() с Expression не применяет префикс.
     */
    #[Test]
    public function joinExpressionDoesNotApplyPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $raw = $conn->query()->raw('clients c');

        $built = $conn->query()
            ->select()
            ->from('users')
            ->join($raw, 'c.user_id = users.id')
            ->toSql();

        self::assertSame('SELECT * FROM "t_users" INNER JOIN clients c ON "c"."user_id" = "users"."id"', $built['sql']);
    }

    /**
     * Проверяет, что first() проксирует вызов fetchOne().
     */
    #[Test]
    public function firstCallsFetchOne(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $conn->query()
            ->select()
            ->from('users')
            ->where('id = :id', ['id' => 1])
            ->first();

        self::assertCount(1, $conn->executed);
        self::assertSame('SELECT * FROM "users" WHERE ("id" = :id)', $conn->executed[0]['sql']);
    }

    /**
     * Проверяет, что value() возвращает null, если строка не найдена.
     */
    #[Test]
    public function valueReturnsNullWhenRowMissing(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $value = $conn->query()
            ->select()
            ->from('users')
            ->value('name');

        self::assertNull($value);
    }

    /**
     * Проверяет, что orWhere() корректно вставляет OR.
     */
    #[Test]
    public function orWhereBuildsOr(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('id = :id', ['id' => 1])
            ->orWhere('name = :name', ['name' => 'Bob'])
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("id" = :id) OR ("name" = :name)', $built['sql']);
        self::assertSame(['id' => 1, 'name' => 'Bob'], $built['params']);
    }

    /**
     * Проверяет whereNull()/whereNotNull().
     */
    #[Test]
    public function whereNullAndNotNull(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("deleted_at" IS NULL) AND ("email" IS NOT NULL)', $built['sql']);
    }

    /**
     * Проверяет whereIn() и генерацию плейсхолдеров.
     */
    #[Test]
    public function whereInGeneratesNamedPlaceholders(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereIn('id', [1, 2, 3])
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("id" IN (:in_1, :in_2, :in_3))', $built['sql']);
        self::assertSame(['in_1' => 1, 'in_2' => 2, 'in_3' => 3], $built['params']);
    }

    /**
     * Проверяет, что пустой whereIn() превращается в 1=0.
     */
    #[Test]
    public function whereInEmptyValuesIsAlwaysFalse(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereIn('id', [])
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE (1 = 0)', $built['sql']);
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет orWhereIn() и отсутствие конфликтов плейсхолдеров между несколькими IN.
     */
    #[Test]
    public function orWhereInDoesNotConflictPlaceholders(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereIn('id', [1, 2])
            ->orWhereIn('age', [30])
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("id" IN (:in_1, :in_2)) OR ("age" IN (:in_3))', $built['sql']);
        self::assertSame(['in_1' => 1, 'in_2' => 2, 'in_3' => 30], $built['params']);
    }

    /**
     * Проверяет генерацию SQL для INSERT и подстановку префикса.
     */
    #[Test]
    public function insertBuildsSqlAndParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()->insert('users', ['name' => 'Alice', 'age' => 10])->toSql();

        self::assertSame('INSERT INTO "t_users" ("name", "age") VALUES (:v_1, :v_2)', $built['sql']);
        self::assertSame(['v_1' => 'Alice', 'v_2' => 10], $built['params']);
    }

    /**
     * Проверяет INSERT DEFAULT VALUES при пустом data.
     */
    #[Test]
    public function insertDefaultValuesWhenDataEmpty(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()->insert('users', [])->toSql();

        self::assertSame('INSERT INTO "t_users" DEFAULT VALUES', $built['sql']);
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет, что execute() для INSERT вызывает Connection::execute().
     */
    #[Test]
    public function insertExecuteCallsConnectionExecute(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $conn->query()->insert('users', ['name' => 'Alice'])->execute();

        self::assertCount(1, $conn->executed);
        self::assertSame('INSERT INTO "t_users" ("name") VALUES (:v_1)', $conn->executed[0]['sql']);
        self::assertSame(['v_1' => 'Alice'], $conn->executed[0]['params']);
    }

    /**
     * Проверяет генерацию SQL для UPDATE (SET + WHERE) и merge параметров.
     */
    #[Test]
    public function updateBuildsSqlAndParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->update('users', ['name' => 'Bob'])
            ->where('id = :id', ['id' => 5])
            ->toSql();

        self::assertSame('UPDATE "t_users" SET "name" = :v_1 WHERE ("id" = :id)', $built['sql']);
        self::assertSame(['v_1' => 'Bob', 'id' => 5], $built['params']);
    }

    /**
     * Проверяет генерацию SQL для DELETE + WHERE.
     */
    #[Test]
    public function deleteBuildsSqlAndParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->delete('users')
            ->where('id = :id', ['id' => 9])
            ->toSql();

        self::assertSame('DELETE FROM "t_users" WHERE ("id" = :id)', $built['sql']);
        self::assertSame(['id' => 9], $built['params']);
    }

    /**
     * Проверяет LEFT JOIN и префикс для joined-таблицы.
     */
    #[Test]
    public function leftJoinAppliesPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->select()
            ->from('users')
            ->leftJoin('clients', 'clients.user_id = users.id')
            ->toSql();

        self::assertSame('SELECT * FROM "t_users" LEFT JOIN "t_clients" ON "clients"."user_id" = "users"."id"', $built['sql']);
    }

    /**
     * Проверяет RIGHT JOIN и префикс для joined-таблицы.
     */
    #[Test]
    public function rightJoinAppliesPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->select()
            ->from('users')
            ->rightJoin('clients', 'clients.user_id = users.id')
            ->toSql();

        self::assertSame('SELECT * FROM "t_users" RIGHT JOIN "t_clients" ON "clients"."user_id" = "users"."id"', $built['sql']);
    }

    /**
     * Проверяет leftJoinRaw().
     */
    #[Test]
    public function leftJoinRawDoesNotApplyPrefix(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'), prefix: 't_');

        $built = $conn->query()
            ->select()
            ->from('users')
            ->leftJoinRaw('clients c', 'c.user_id = users.id')
            ->toSql();

        self::assertSame('SELECT * FROM "t_users" LEFT JOIN clients c ON "c"."user_id" = "users"."id"', $built['sql']);
    }

    /**
     * Проверяет GROUP BY для одной колонки.
     */
    #[Test]
    public function groupByBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['client_id', 'COUNT(*) AS cnt'])
            ->from('users')
            ->groupBy('client_id')
            ->toSql();

        self::assertSame('SELECT "client_id", COUNT(*) AS "cnt" FROM "users" GROUP BY "client_id"', $built['sql']);
    }

    /**
     * Проверяет HAVING/orHaving и merge параметров с WHERE.
     */
    #[Test]
    public function havingBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['client_id', 'COUNT(*) AS cnt'])
            ->from('users')
            ->where('status = :status', ['status' => 'active'])
            ->groupBy('client_id')
            ->having('COUNT(*) > :min', ['min' => 10])
            ->orHaving('COUNT(*) = :eq', ['eq' => 0])
            ->toSql();

        self::assertSame(
            'SELECT "client_id", COUNT(*) AS "cnt" FROM "users" WHERE ("status" = :status) GROUP BY "client_id" HAVING (COUNT(*) > :min) OR (COUNT(*) = :eq)',
            $built['sql'],
        );
        self::assertSame(['status' => 'active', 'min' => 10, 'eq' => 0], $built['params']);
    }

    /**
     * Проверяет whereLike() и whereNotLike() с генерацией плейсхолдеров.
     */
    #[Test]
    public function whereLikeBuildsSqlAndParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereLike('name', '%Al%')
            ->whereNotLike('email', '%@spam%')
            ->toSql();

        self::assertSame('SELECT * FROM "users" WHERE ("name" LIKE :like_1) AND ("email" NOT LIKE :like_2)', $built['sql']);
        self::assertSame(['like_1' => '%Al%', 'like_2' => '%@spam%'], $built['params']);
    }

    /**
     * Проверяет whereBetween()/orWhereBetween()/whereNotBetween() и генерацию плейсхолдеров.
     */
    #[Test]
    public function whereBetweenBuildsSqlAndParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereBetween('age', 10, 20)
            ->orWhereBetween('id', 1, 5)
            ->whereNotBetween('created_at', '2026-01-01', '2026-01-31')
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("age" BETWEEN :between_1 AND :between_2) OR ("id" BETWEEN :between_3 AND :between_4) AND ("created_at" NOT BETWEEN :between_5 AND :between_6)',
            $built['sql'],
        );

        self::assertSame(
            [
                'between_1' => 10,
                'between_2' => 20,
                'between_3' => 1,
                'between_4' => 5,
                'between_5' => '2026-01-01',
                'between_6' => '2026-01-31',
            ],
            $built['params'],
        );
    }

    /**
     * Проверяет групповое API: where(callable) должен собрать вложенные условия в скобках.
     */
    #[Test]
    public function whereCallableBuildsGroupedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->where(function ($query): void {
                $query
                    ->where('age > :age', ['age' => 30])
                    ->orWhere('email = :email', ['email' => 'john@example.com']);
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) AND (("age" > :age) OR ("email" = :email))',
            $built['sql'],
        );
        self::assertSame(['age' => 30, 'email' => 'john@example.com'], $built['params']);
    }

    /**
     * Проверяет групповое API: orWhere(callable) добавляет группу с OR.
     */
    #[Test]
    public function orWhereCallableBuildsGroupedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->orWhere(function ($query): void {
                $query
                    ->where('role = :role', ['role' => 'admin'])
                    ->whereNotNull('email');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) OR (("role" = :role) AND ("email" IS NOT NULL))',
            $built['sql'],
        );
        self::assertSame(['role' => 'admin'], $built['params']);
    }

    /**
     * Проверяет вложенные группы where(callable) (группа внутри группы) и корректные скобки.
     */
    #[Test]
    public function nestedWhereCallableBuildsGroupedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where(function ($q): void {
                $q->where('active = 1');

                $q->where(function ($q2): void {
                    $q2
                        ->where('age > :age', ['age' => 30])
                        ->orWhere('role = :role', ['role' => 'admin']);
                });
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE (("active" = 1) AND (("age" > :age) OR ("role" = :role)))',
            $built['sql'],
        );
        self::assertSame(['age' => 30, 'role' => 'admin'], $built['params']);
    }

    /**
     * Проверяет, что whereIn/whereLike/whereBetween внутри группы попадают внутрь этой группы.
     */
    #[Test]
    public function whereHelpersInsideGroupStayInsideGroup(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->where(function (SelectQueryBuilder $q): void {
                $q
                    ->whereIn('id', [1, 2])
                    ->orWhereLike('email', '%@example.com')
                    ->whereBetween('age', 18, 65);
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) AND (("id" IN (:in_1, :in_2)) OR ("email" LIKE :like_3) AND ("age" BETWEEN :between_4 AND :between_5))',
            $built['sql'],
        );

        self::assertSame(
            [
                'in_1'      => 1,
                'in_2'      => 2,
                'like_3'    => '%@example.com',
                'between_4' => 18,
                'between_5' => 65,
            ],
            $built['params'],
        );
    }

    /**
     * Проверяет групповое API для HAVING: having(callable)/orHaving(callable) должны собирать условия в скобках.
     */
    #[Test]
    public function havingCallableBuildsGroupedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['client_id', 'COUNT(*) AS cnt'])
            ->from('users')
            ->where('status = :status', ['status' => 'active'])
            ->groupBy('client_id')
            ->having(function (SelectQueryBuilder $q): void {
                $q
                    ->having('COUNT(*) > :min', ['min' => 10])
                    ->orHaving('COUNT(*) = :eq', ['eq' => 0]);
            })
            ->toSql();

        self::assertSame(
            'SELECT "client_id", COUNT(*) AS "cnt" FROM "users" WHERE ("status" = :status) GROUP BY "client_id" HAVING ((COUNT(*) > :min) OR (COUNT(*) = :eq))',
            $built['sql'],
        );
        self::assertSame(['status' => 'active', 'min' => 10, 'eq' => 0], $built['params']);
    }

    /**
     * Проверяет orHaving(callable) и смешивание строковых HAVING с группами.
     */
    #[Test]
    public function orHavingCallableBuildsGroupedSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['client_id', 'COUNT(*) AS cnt'])
            ->from('users')
            ->groupBy('client_id')
            ->having('COUNT(*) > :min', ['min' => 10])
            ->orHaving(function (SelectQueryBuilder $q): void {
                $q
                    ->having('COUNT(*) < :max', ['max' => 100])
                    ->having('COUNT(*) != :neq', ['neq' => 50]);
            })
            ->toSql();

        self::assertSame(
            'SELECT "client_id", COUNT(*) AS "cnt" FROM "users" GROUP BY "client_id" HAVING (COUNT(*) > :min) OR ((COUNT(*) < :max) AND (COUNT(*) != :neq))',
            $built['sql'],
        );

        self::assertSame(['min' => 10, 'max' => 100, 'neq' => 50], $built['params']);
    }

    /**
     * Проверяет DISTINCT.
     */
    #[Test]
    public function distinctAddsDistinctKeyword(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['email'])
            ->distinct()
            ->from('users')
            ->toSql();

        self::assertSame('SELECT DISTINCT "email" FROM "users"', $built['sql']);
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет WHERE EXISTS с подзапросом через callback и мердж параметров.
     */
    #[Test]
    public function whereExistsCallbackBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = :active', ['active' => 1])
            ->whereExists(function (SelectQueryBuilder $q): void {
                $q
                    ->select('1')
                    ->from('orders')
                    ->where('orders.user_id = users.id')
                    ->where('orders.status = :st', ['st' => 'paid']);
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = :active) AND (EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "users"."id") AND ("orders"."status" = :st)))',
            $built['sql'],
        );
        self::assertSame(['active' => 1, 'st' => 'paid'], $built['params']);
    }

    /**
     * Проверяет WHERE NOT EXISTS.
     */
    #[Test]
    public function whereNotExistsCallbackBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereNotExists(function (SelectQueryBuilder $q): void {
                $q
                    ->select('1')
                    ->from('orders')
                    ->where('orders.user_id = users.id');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE (NOT EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "users"."id")))',
            $built['sql'],
        );
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет UNION и объединение params.
     */
    #[Test]
    public function unionBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $q2 = $conn->query()
            ->select(['id'])
            ->from('users')
            ->where('status = :st', ['st' => 'archived']);

        $built = $conn->query()
            ->select(['id'])
            ->from('users')
            ->where('status = :st2', ['st2' => 'active'])
            ->union($q2)
            ->toSql();

        self::assertSame(
            'SELECT "id" FROM "users" WHERE ("status" = :st2) UNION (SELECT "id" FROM "users" WHERE ("status" = :st))',
            $built['sql'],
        );
        self::assertSame(['st2' => 'active', 'st' => 'archived'], $built['params']);
    }

    /**
     * Проверяет UNION ALL через callback.
     */
    #[Test]
    public function unionAllCallbackBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['id'])
            ->from('users')
            ->unionAll(function (SelectQueryBuilder $q): void {
                $q
                    ->select(['id'])
                    ->from('users')
                    ->where('id > :min', ['min' => 10]);
            })
            ->toSql();

        self::assertSame(
            'SELECT "id" FROM "users" UNION ALL (SELECT "id" FROM "users" WHERE ("id" > :min))',
            $built['sql'],
        );
        self::assertSame(['min' => 10], $built['params']);
    }

    /**
     * Проверяет OR WHERE EXISTS с подзапросом через callback.
     */
    #[Test]
    public function orWhereExistsCallbackBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->orWhereExists(function (SelectQueryBuilder $q): void {
                $q->select('1')->from('orders')->where('orders.user_id = users.id');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) OR (EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "users"."id")))',
            $built['sql'],
        );
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет OR WHERE NOT EXISTS.
     */
    #[Test]
    public function orWhereNotExistsCallbackBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->orWhereNotExists(function (SelectQueryBuilder $q): void {
                $q->select('1')->from('orders')->where('orders.user_id = users.id');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) OR (NOT EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "users"."id")))',
            $built['sql'],
        );
        self::assertSame([], $built['params']);
    }

    /**
     * Проверяет UNION ALL с готовым builder'ом (не callback) и мердж параметров.
     */
    #[Test]
    public function unionAllBuilderBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $q2 = $conn->query()
            ->select(['id'])
            ->from('users')
            ->where('id > :min', ['min' => 10]);

        $built = $conn->query()
            ->select(['id'])
            ->from('users')
            ->where('id < :max', ['max' => 100])
            ->unionAll($q2)
            ->toSql();

        self::assertSame(
            'SELECT "id" FROM "users" WHERE ("id" < :max) UNION ALL (SELECT "id" FROM "users" WHERE ("id" > :min))',
            $built['sql'],
        );

        self::assertSame(['max' => 100, 'min' => 10], $built['params']);
    }

    /**
     * Проверяет FROM (subquery) AS alias и мердж params из подзапроса.
     */
    #[Test]
    public function fromSubqueryBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['u.id'])
            ->fromSubquery(function (SelectQueryBuilder $q): void {
                $q
                    ->select(['id', 'email'])
                    ->from('users')
                    ->where('email LIKE :email', ['email' => '%@example.com']);
            }, 'u')
            ->where('u.id > :min', ['min' => 10])
            ->toSql();

        self::assertSame(
            'SELECT "u"."id" FROM (SELECT "id", "email" FROM "users" WHERE ("email" LIKE :email)) AS "u" WHERE ("u"."id" > :min)',
            $built['sql'],
        );

        self::assertSame(['email' => '%@example.com', 'min' => 10], $built['params']);
    }

    /**
     * Проверяет JOIN (subquery) AS alias ON ... и мердж params из подзапроса join.
     */
    #[Test]
    public function joinSubqueryBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['u.id', 'o.cnt'])
            ->from('users u')
            ->joinSubquery(function (SelectQueryBuilder $q): void {
                $q
                    ->select(['user_id', 'COUNT(*) AS cnt'])
                    ->from('orders')
                    ->where('status = :st', ['st' => 'paid'])
                    ->groupBy('user_id');
            }, 'o', 'o.user_id = u.id')
            ->where('u.active = :active', ['active' => 1])
            ->toSql();

        self::assertSame(
            'SELECT "u"."id", "o"."cnt" FROM "users" AS "u" INNER JOIN (SELECT "user_id", COUNT(*) AS "cnt" FROM "orders" WHERE ("status" = :st) GROUP BY "user_id") AS "o" ON "o"."user_id" = "u"."id" WHERE ("u"."active" = :active)',
            $built['sql'],
        );

        self::assertSame(['st' => 'paid', 'active' => 1], $built['params']);
    }

    /**
     * Проверяет LEFT JOIN subquery.
     */
    #[Test]
    public function leftJoinSubqueryBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['u.id', 'o.cnt'])
            ->from('users u')
            ->leftJoinSubquery(function (SelectQueryBuilder $q): void {
                $q->select(['user_id', 'COUNT(*) AS cnt'])->from('orders')->groupBy('user_id');
            }, 'o', 'o.user_id = u.id')
            ->toSql();

        self::assertSame(
            'SELECT "u"."id", "o"."cnt" FROM "users" AS "u" LEFT JOIN (SELECT "user_id", COUNT(*) AS "cnt" FROM "orders" GROUP BY "user_id") AS "o" ON "o"."user_id" = "u"."id"',
            $built['sql'],
        );
    }

    /**
     * Проверяет, что fromSubquery() оборачивает запрос в скобки.
     */
    #[Test]
    public function fromSubqueryWrapsInParentheses(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $sub = $conn->query()->select(['id'])->from('users')->where('id > 10');

        $built = $conn->query()->select()->fromSubquery($sub, 'u')->toSql();

        self::assertSame('SELECT * FROM (SELECT "id" FROM "users" WHERE ("id" > 10)) AS "u"', $built['sql']);
    }

    /**
     * Проверяет, что joinSubquery() оборачивает запрос в скобки.
     */
    #[Test]
    public function joinSubqueryWrapsInParentheses(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $sub = $conn->query()->select(['user_id', 'COUNT(*) AS cnt'])->from('orders')->groupBy('user_id');

        $built = $conn->query()
            ->select(['u.id', 'o.cnt'])
            ->from('users u')
            ->joinSubquery($sub, 'o', 'o.user_id = u.id')
            ->toSql();

        self::assertSame(
            'SELECT "u"."id", "o"."cnt" FROM "users" AS "u" INNER JOIN (SELECT "user_id", COUNT(*) AS "cnt" FROM "orders" GROUP BY "user_id") AS "o" ON "o"."user_id" = "u"."id"',
            $built['sql'],
        );
    }

    /**
     * Проверяет, что selectExists() добавляет EXISTS и мерджит параметры подзапроса.
     */
    #[Test]
    public function selectExistsAddsColumnAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['u.id'])
            ->selectExists(function (SelectQueryBuilder $q): void {
                $q->select('1')->from('orders')->where('orders.user_id = u.id')->where('status = :st', ['st' => 'paid']);
            }, 'has_paid_orders')
            ->from('users u')
            ->toSql();

        self::assertSame(
            'SELECT "u"."id", EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "u"."id") AND ("status" = :st)) AS "has_paid_orders" FROM "users" AS "u"',
            $built['sql'],
        );
        self::assertSame(['st' => 'paid'], $built['params']);
    }

    /**
     * Проверяет, что selectNotExists() добавляет NOT EXISTS.
     */
    #[Test]
    public function selectNotExistsAddsColumn(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select(['u.id'])
            ->selectNotExists(function (SelectQueryBuilder $q): void {
                $q->select('1')->from('orders')->where('orders.user_id = u.id');
            }, 'no_orders')
            ->from('users u')
            ->toSql();

        self::assertSame(
            'SELECT "u"."id", NOT EXISTS (SELECT 1 FROM "orders" WHERE ("orders"."user_id" = "u"."id")) AS "no_orders" FROM "users" AS "u"',
            $built['sql'],
        );
    }

    /**
     * Проверяет правило: если есть UNION и при этом ORDER/LIMIT/OFFSET, то мы оборачиваем запросы.
     */
    #[Test]
    public function unionWithOrderByAndLimitIsWrapped(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $q2 = $conn->query()->select(['id'])->from('users')->where('id > :min', ['min' => 10]);

        $built = $conn->query()
            ->select(['id'])
            ->from('users')
            ->unionAll($q2)
            ->orderBy('id', 'DESC')
            ->limit(5)
            ->toSql();

        self::assertSame(
            'SELECT * FROM (SELECT "id" FROM "users" UNION ALL (SELECT "id" FROM "users" WHERE ("id" > :min))) AS _u ORDER BY "id" DESC LIMIT 5',
            $built['sql'],
        );
        self::assertSame(['min' => 10], $built['params']);
    }

    /**
     * Проверяет whereInSubquery() через callback и мердж параметров.
     */
    #[Test]
    public function whereInSubqueryCallbackBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereInSubquery('id', function (SelectQueryBuilder $q): void {
                $q->select('user_id')->from('orders')->where('status = :st', ['st' => 'paid']);
            })
            ->where('active = :active', ['active' => 1])
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE (id IN (SELECT "user_id" FROM "orders" WHERE ("status" = :st))) AND ("active" = :active)',
            $built['sql'],
        );
        self::assertSame(['st' => 'paid', 'active' => 1], $built['params']);
    }

    /**
     * Проверяет orWhereInSubquery() с готовым builder.
     */
    #[Test]
    public function orWhereInSubqueryBuilderBuildsSqlAndMergesParams(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $sub = $conn->query()->select('user_id')->from('orders')->where('status = :st', ['st' => 'paid']);

        $built = $conn->query()
            ->select()
            ->from('users')
            ->where('active = 1')
            ->orWhereInSubquery('id', $sub)
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE ("active" = 1) OR (id IN (SELECT "user_id" FROM "orders" WHERE ("status" = :st)))',
            $built['sql'],
        );
        self::assertSame(['st' => 'paid'], $built['params']);
    }

    /**
     * Проверяет whereNotInSubquery().
     */
    #[Test]
    public function whereNotInSubqueryBuildsSql(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $built = $conn->query()
            ->select()
            ->from('users')
            ->whereNotInSubquery('id', function (SelectQueryBuilder $q): void {
                $q->select('user_id')->from('orders');
            })
            ->toSql();

        self::assertSame(
            'SELECT * FROM "users" WHERE (id NOT IN (SELECT "user_id" FROM "orders"))',
            $built['sql'],
        );
    }

    /**
     * Проверяет, что count() сбрасывает limit/offset и делает запрос к Connection::fetchOne().
     */
    #[Test]
    public function countBuildsAggregateSql(): void
    {
        $conn = new class (new FakePdo('sqlite')) extends SpyConnection {
            public function fetchOne(string $sql, array $params = []): ?array
            {
                parent::fetchOne($sql, $params);

                return ['__agg' => '5'];
            }
        };

        $count = $conn->query()
            ->select()
            ->from('users')
            ->where('active = :active', ['active' => 1])
            ->orderBy('id', 'DESC')
            ->limit(10)
            ->offset(20)
            ->count();

        self::assertSame(5, $count);
        self::assertCount(1, $conn->executed);
        self::assertSame('SELECT COUNT(*) AS __agg FROM "users" WHERE ("active" = :active)', $conn->executed[0]['sql']);
        self::assertSame(['active' => 1], $conn->executed[0]['params']);
    }

    /**
     * Проверяет sum/avg/min/max.
     */
    #[Test]
    public function aggregatesBuildCorrectSql(): void
    {
        $conn = new class (new FakePdo('sqlite')) extends SpyConnection {
            private int $i = 0;

            public function fetchOne(string $sql, array $params = []): ?array
            {
                parent::fetchOne($sql, $params);

                $this->i++;

                return match ($this->i) {
                    1       => ['__agg' => '10'],    // SUM
                    2       => ['__agg' => '2.5'],   // AVG
                    3       => ['__agg' => '1'],     // MIN
                    4       => ['__agg' => '99'],    // MAX
                    default => null,
                };
            }
        };

        $qb = $conn->query()->select()->from('orders');

        self::assertSame(10, $qb->sum('price'));
        self::assertSame(2.5, $qb->avg('price'));
        self::assertSame('1', (string) $qb->min('price'));
        self::assertSame('99', (string) $qb->max('price'));

        self::assertSame('SELECT SUM(price) AS __agg FROM "orders"', $conn->executed[0]['sql']);
        self::assertSame('SELECT AVG(price) AS __agg FROM "orders"', $conn->executed[1]['sql']);
        self::assertSame('SELECT MIN(price) AS __agg FROM "orders"', $conn->executed[2]['sql']);
        self::assertSame('SELECT MAX(price) AS __agg FROM "orders"', $conn->executed[3]['sql']);
    }

    /**
     * Проверяет paginate(): data + meta.
     */
    #[Test]
    public function paginateBuildsItemsAndTotal(): void
    {
        $conn = new class (new FakePdo('sqlite')) extends SpyConnection {
            private int $fetchOneCalls = 0;

            public function fetchAll(string $sql, array $params = []): array
            {
                parent::fetchAll($sql, $params);

                return [
                    ['id' => 3],
                    ['id' => 4],
                ];
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                parent::fetchOne($sql, $params);
                $this->fetchOneCalls++;

                // paginate() вызывает count() -> aggregate() -> fetchOne
                return ['__agg' => '42'];
            }
        };

        $result = $conn->query()
            ->select()
            ->from('users')
            ->orderBy('id', 'ASC')
            ->paginate(page: 2, perPage: 2);

        $payload = $result->toArray();

        self::assertSame(42, $payload['meta']['total']);
        self::assertSame(2, $payload['meta']['current_page']);
        self::assertSame(2, $payload['meta']['per_page']);
        self::assertSame(21, $payload['meta']['last_page']);
        self::assertSame([['id' => 3], ['id' => 4]], $payload['data']);

        // 1) COUNT без limit/offset/orderBy
        self::assertSame('SELECT COUNT(*) AS __agg FROM "users"', $conn->executed[0]['sql']);
        // 2) items запрос с ORDER BY + LIMIT/OFFSET
        self::assertSame('SELECT * FROM "users" ORDER BY "id" ASC LIMIT 2 OFFSET 2', $conn->executed[1]['sql']);
    }
}
