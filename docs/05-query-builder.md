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

## SELECT raw и strict

`select()` теперь для простых колонок (`id`, `u.name`, `u.*`, `u.name AS user_name`).

Сложные выражения (`COUNT(...)`, `COALESCE(...)`, `CASE ...`) — только через:

```php
$qb->selectRaw('COUNT(*) AS total');
// или
$qb->select(new \PhpSoftBox\Database\QueryBuilder\Expression('COUNT(*) AS total'));
```

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
