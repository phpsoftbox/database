# Warmup строк

`Connection::warmup()` даёт lifecycle-scoped прогрев строк по идентификаторным ключам.
Это не persistent cache и не общий query cache: данные живут в памяти процесса (store группы подключений)
и используются, чтобы переиспользовать строки, уже полученные по явному ключу.

```php
use PhpSoftBox\DatabaseLookup\LookupSpec;

$products = LookupSpec::forTable('products')
    ->lookupColumn('id')
    ->values([1, 2, 3]);

$rows = $conn->warmup()->manyUnique($products);
$row = $conn->warmup()->one($products->value(1));
```

Первый вызов сделает один `SELECT ... WHERE id IN (...)`, запомнит найденные строки и
отдельно запомнит отсутствующие идентификаторы. Повторный вызов по тем же ключам не
пойдёт в БД, а если запрошена смесь прогретых и новых id, запрос уйдёт только по miss-значениям.

`manyUnique()` и `one()` предназначены только для lookup-ов, где один lookup key
соответствует максимум одной строке, например primary key. Если БД вернет несколько строк
на один key, `manyUnique()` выбросит `UnexpectedValueException`.

Для one-to-many lookup используйте `manyGrouped()`:

```php
$shipmentProducts = LookupSpec::forTable('shipment_products')
    ->lookupColumn('shipment_id')
    ->values([123, 456]);

$rows = $conn->warmup()->manyGrouped($shipmentProducts);
```

В grouped-режиме warmup store хранит `list<row>` на каждый lookup key, поэтому строки
с одинаковым `shipment_id` не перезаписывают друг друга.

Для проверки существования без ручного разбора строк есть отдельный helper:

```php
$found = $conn->warmup()->existingValues($products);
```

## Условия и составные ключи

Если запрос содержит фиксированные условия, default warmup key строится из fixed criteria
и lookup-column:

```php
$lookup = LookupSpec::forTable('shipment_products')
    ->lookupColumn('product_id')
    ->values([10, 20])
    ->where('shipment_id', 123);

$rows = $conn->warmup()->manyUnique($lookup);
```

SQL при miss будет содержать `shipment_id = 123 AND product_id IN (...)`, а ключом
для warmup станет пара `shipment_id + product_id`. Явный ключ можно задать через
`keyColumns(...)`; он должен включать lookup-column и все fixed criteria columns.

## Режимы чтения

По умолчанию используется `WarmupReadMode::Use`: сначала читаем прогретые значения,
miss-значения дозагружаем из БД и запоминаем.

`WarmupReadMode::Fresh` принудительно читает из БД и обновляет warmup-записи.

`WarmupReadMode::Bypass` читает из БД без чтения и записи warmup store.

```php
use PhpSoftBox\Database\Warmup\WarmupReadMode;
use PhpSoftBox\DatabaseLookup\LookupSpec;

$fresh = $conn->warmup()->one(
    LookupSpec::forTable('products')->lookupColumn('id')->value(1),
    WarmupReadMode::Fresh,
);
```

## Инвалидация

Write-операции через `Connection::execute()` очищают warmup store подключения.
Это грубая, но предсказуемая инвалидация в рамках текущего lifecycle: после insert/update/delete
следующее чтение заново пойдёт в БД. Откат транзакции (`transaction()` с исключением) тоже очищает store:
строки, прочитанные внутри транзакции, могли содержать откатываемые изменения.

`DatabaseFactory` создаёт **один store на группу подключений**: `main`, `main.read`, `main.write`
и `default` (если `connections.default = 'main'`) используют общий store. Поэтому запись через
`main.write` инвалидирует прогретые строки `main.read` и `default`. Если в конструктор `DatabaseFactory`
передан собственный `WarmupStore`, он общий для всех подключений фабрики.

## Долгоживущие воркеры

Store живёт, пока живут объекты подключений. В воркерах (очереди, RoadRunner/Swoole) очищайте его
между запросами/задачами, иначе будут отдаваться строки, изменённые другими процессами:

```php
$database->clearWarmup();            // Database: все созданные подключения
$connectionManager->clearWarmup();   // ConnectionManager: все созданные подключения
$databaseFactory->clearWarmup();     // DatabaseFactory: все store групп (и переданный общий store)
$connection->clearWarmup();          // одно подключение (store его группы)
```

Размер store ограничен: по умолчанию 10 000 записей, при переполнении вытесняются самые старые (FIFO).
Лимит задаётся в конфиге фабрики:

```php
return [
    'connections' => [/* ... */],
    'warmup' => [
        'max_entries' => 5000,
    ],
];
```

или напрямую: `new WarmupStore(maxEntries: 5000)`.

Warmup предназначен только для явных keyed-read сценариев. Произвольные запросы с сортировками,
проекциями, join-ами и доменными фильтрами должны оставаться обычными запросами или отдельными
read-model warmup-ами на уровне приложения.
