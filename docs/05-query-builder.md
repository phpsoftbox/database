# QueryBuilder

## Агрегации

`SelectQueryBuilder` поддерживает:
- `count()`
- `sum($column)`
- `avg($column)`
- `min($column)`
- `max($column)`

Пример:

```php
$total = $conn->query()->select()->from('users')->where('active = 1')->count();
$minId = $conn->query()->select()->from('users')->min('id');
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
