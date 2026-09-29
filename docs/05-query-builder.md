# QueryBuilder

## CompiledQuery

`QueryBuilder` теперь разделяет этапы:
- `compile()` — компиляция в named SQL + named bindings (`CompiledQuery`)
- выполнение (`fetchAll/fetchOne/execute`) — подготовка под драйвер (внутри `Connection`)

Пример:

```php
$compiled = $conn->query()
    ->select(['id', 'name'])
    ->from('users')
    ->where('name LIKE :query', ['query' => '%john%'])
    ->compile();

// string
$compiled->sql;

// array<string|int, mixed>
$compiled->bindings;
```

`toSql()` оставлен как legacy-обёртка и возвращает массив формата:

```php
[
    'sql' => '...',
    'params' => [...],
]
```

## Named vs Positional

Внешний API остаётся именованным (`:name`), но перед `PDO::prepare()` внутри `Connection` запрос переводится в positional (`?`) при безопасных условиях.

Конвертация НЕ применяется, если:
- смешаны named и positional параметры;
- в SQL есть placeholder без значения;
- переданы лишние named-параметры, отсутствующие в SQL.

## Подзапросы и UNION

Подзапросы (`whereInSubquery()`, `whereExists()`, `fromSubquery()`, `joinSubquery()`, `selectExists()`)
и части `union()`/`unionAll()` компилируются отдельными билдерами, у каждого из которых своя нумерация
автоматических параметров (`:in_1`, `:where_1`, ...). Чтобы значения не подменяли друг друга, при встраивании
все именованные параметры подзапроса получают уникальный префикс `__sq{N}_`:

```php
$archived = $conn->query()->select('id')->from('users')->whereIn('id', [3, 4]);

$compiled = $conn->query()
    ->select('id')
    ->from('users')
    ->whereIn('id', [1, 2])
    ->union($archived)
    ->compile();

// SELECT "id" FROM "users" WHERE ("id" IN (:in_1, :in_2))
//     UNION (SELECT "id" FROM "users" WHERE ("id" IN (:__sq1_in_1, :__sq1_in_2)))
// bindings: ['in_1' => 1, 'in_2' => 2, '__sq1_in_1' => 3, '__sq1_in_2' => 4]
```

Имена параметров, переданных в подзапрос вручную (`['status' => ...]`), тоже получают префикс — не
рассчитывайте на них в собранном `CompiledQuery`. Не используйте собственные имена параметров, начинающиеся
с `__sq`.

Части UNION встраиваются в скобках: `... UNION (SELECT ...)`. SQLite скобки вокруг частей UNION не
поддерживает, поэтому для него используется форма `... UNION SELECT * FROM (SELECT ...)`.

## INSERT без колонок

`insert('table', [])` вставляет строку со значениями по умолчанию: `INSERT INTO t DEFAULT VALUES`
для PostgreSQL и SQLite, `INSERT INTO t () VALUES ()` для MySQL/MariaDB.

## Агрегации

`SelectQueryBuilder` поддерживает:
- `count()`
- `exists()`
- `notExists()`
- `sum($column)`
- `avg($column)`
- `min($column)`
- `max($column)`

Пример:

```php
$total = $conn->query()->select()->from('users')->where('active = 1')->count();
$hasActive = $conn->query()->select()->from('users')->where('active = 1')->exists();
$noActive = $conn->query()->select()->from('users')->where('active = 1')->notExists();
$minId = $conn->query()->select()->from('users')->min('id');
```

`count()` сбрасывает `ORDER BY`/`LIMIT`/`OFFSET`. Если в запросе есть `GROUP BY`, `HAVING`, `DISTINCT`
или `UNION`, подсчёт выполняется обёрткой `SELECT COUNT(*) FROM (<запрос>) AS __count`: возвращается
количество строк результата (групп, уникальных строк, строк объединения), аргумент `$column` в этом
случае не используется. Так же считается `total` в `paginate()`.

```php
// Количество групп, а не размер первой группы.
$clients = $conn->query()->select('client_id')->from('orders')->groupBy('client_id')->count();
```

`sum()`/`avg()`/`min()`/`max()` к группировкам не адаптируются: при `GROUP BY` они вернут значение
для первой группы.

## WHERE DSL и raw

`where()`/`orWhere()` поддерживают структурный массив условий:

```php
$qb->where([
    'u.status' => ':status',
    ['u.created_datetime', '>=', ':created_from'],
    ['u.created_datetime', '<=', ':created_to'],
    'u.id' => [1, 2, 3], // shorthand для IN
], [
    'status' => 'active',
    'created_from' => $fromValue,
    'created_to' => $toValue,
]);
```

Сравнение колонка-колонка:

```php
$qb->where([
    ['column' => 'u.owner_id', 'operator' => '=', 'target_column' => 'o.id'],
]);
```

Для сложных выражений используйте явный raw API:

```php
$qb->whereRaw('COALESCE(u.total_bytes, 0) > :min_total', ['min_total' => 0]);
$qb->orWhereRaw('u.last_reported_datetime IS NOT NULL');
```

`where(string)` и `having(string)` теперь принимают только простые условия.
Сложный SQL (скобки, `AND/OR`, `EXISTS/SELECT`, функции) нужно писать только через `*Raw()` или через структурные helper-методы (`whereExists`, `whereNotExists`, `whereIn`, ...).

`havingRaw()`/`orHavingRaw()` работают аналогично:

```php
$qb->groupBy('client_id')
   ->havingRaw('COUNT(*) > :min', ['min' => 10])
   ->orHavingRaw('SUM(total) > :sum', ['sum' => 1000]);
```

## Экранирование идентификаторов

Имена колонок и таблиц (ключи `insert()`/`update()`, колонки `orderBy()`/`groupBy()`, ключи и колонки
массивного `where()`, `Connection::quoteIdentifier()`/`quoteTable()`) принимаются только в двух формах:

- простое имя из букв, цифр и `_`, при необходимости через точку: `id`, `u.name`, `public.users.id`, `u.*`;
- уже экранированное имя в кавычках **текущего** диалекта с удвоенными внутренними кавычками:
  `"order"` для PostgreSQL/SQLite, `` `order` `` для MySQL/MariaDB.

Любая другая строка (пробелы, операторы, запятые, кавычки другого диалекта) приводит к
`InvalidArgumentException`. Раньше строка в кавычках по краям считалась «уже экранированной», и ключ
вида `'"is_admin" = true, "name"'` из данных запроса встраивался в `UPDATE ... SET` как SQL.

```php
$conn->query()->update('users', ['"is_admin" = true, "name"' => 'x']); // InvalidArgumentException при компиляции
$conn->quoteIdentifier('user`name');                                     // InvalidArgumentException
```

Операторы массивного `where()` ограничены списком: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `<=>`,
`LIKE`, `NOT LIKE`, `ILIKE`, `NOT ILIKE`, `IN`, `NOT IN`, `IS`, `IS NOT`, `IS [NOT] DISTINCT FROM`.

Алиасы (`fromSubquery(..., 'alias')`, `selectExists(..., 'alias')`) по-прежнему могут содержать любой текст:
он всегда экранируется целиком. Имя таблицы допускает алиас с `AS` и без него: `users u`, `users AS u`.

### Условия where()/having()/ON

Простые строковые условия (`where('status = :status')`, условие `join(..., 'o.user_id = u.id')`)
экранируются эвристикой: в кавычки берутся только имена колонок. Не изменяются:

- строковые литералы: `name = 'hello world'`;
- ключевые слова и операторы: `AND`, `OR`, `IS NULL`, `ILIKE`, `CURRENT_TIMESTAMP`, `CURRENT_DATE`, ...;
- имена функций (слово перед `(`), префиксы типизированных литералов (`DATE '2024-01-01'`),
  единицы после `INTERVAL` (`INTERVAL 1 DAY`);
- плейсхолдеры `:name`, приведения `::type`, числа и уже экранированные имена.

## SELECT raw и strict

`select()` теперь для простых колонок (`id`, `u.name`, `u.*`, `u.name AS user_name`).

Сложные выражения (`COUNT(...)`, `COALESCE(...)`, `CASE ...`) — только через:

```php
$qb->selectRaw('COUNT(*) AS total');
// или
$qb->select(new \PhpSoftBox\Database\QueryBuilder\Expression('COUNT(*) AS total'));
```

## UPDATE expressions

Обычные значения в `UPDATE SET` всегда передаются через bind-параметры. Для SQL-выражения
нужно явно использовать `QueryFactory::raw()`:

```php
$query = $conn->query();

$query->update('warehouse_places', [
    'max_load'  => $query->raw('max_load * 1000'),
    'updated_by' => $userId,
])->execute();
```

В этом примере `max_load * 1000` встраивается в SQL, а `$userId` остаётся параметризованным.
Содержимое `raw()` не экранируется и должно состоять только из доверенного SQL, без пользовательского ввода.

## Пагинация

Метод `paginate()` возвращает `PaginationResultInterface` с ключами `data/links/meta`.

```php
$result = $conn->query()
    ->select()
    ->from('users')
    ->orderBy('id', 'DESC')
    ->paginate(page: 2, perPage: 20);
```

Без параметров используется `perPage = 15`:

```php
$result = $conn->query()
    ->select()
    ->from('users')
    ->orderBy('id', 'DESC')
    ->paginate();
```

## Настройка Pagination

**Без DI:**

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Pagination\Paginator;
use PhpSoftBox\Pagination\RequestPaginationContextResolver;

$resolver = new RequestPaginationContextResolver(
    $request,
    perPageParam: 'per_page',
    perPageMax: 100,
);

$paginator = new Paginator(perPage: 20, resolver: $resolver);

$factory = new DatabaseFactory($config, paginator: $paginator);
$conn = $factory->create();
```

**Через DI (PHP-DI):**

```php
use DI\ContainerBuilder;
use function DI\autowire;
use function DI\get;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Configurator\DatabaseFactoryInterface;
use PhpSoftBox\Pagination\Paginator;
use PhpSoftBox\Pagination\RequestPaginationContextResolver;
use Psr\Http\Message\ServerRequestInterface;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    RequestPaginationContextResolver::class => function () {
        return new RequestPaginationContextResolver(
            get(ServerRequestInterface::class),
            perPageParam: 'per_page',
            perPageMax: 100,
        );
    },
    Paginator::class => autowire()
        ->constructor(perPage: 20, resolver: get(RequestPaginationContextResolver::class)),
    DatabaseFactoryInterface::class => function () {
        $config = get('db.config');
        return new DatabaseFactory($config, paginator: get(Paginator::class));
    },
]);
```
