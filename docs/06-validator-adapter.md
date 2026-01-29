# Валидация exists/unique

Можно использовать адаптер для правил `exists`/`unique` из компонента Validator.

```php
use PhpSoftBox\Database\Validator\DatabaseValidationAdapter;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use PhpSoftBox\Validator\Rule\ExistsValidation;
use PhpSoftBox\Validator\Rule\UniqueValidation;

$adapter = new DatabaseValidationAdapter($manager);

$rules = [
    'email' => [
        (new ExistsValidation($adapter))
            ->table('users')
            ->column('email'),
    ],
    'account_id' => [
        (new ExistsValidation($adapter))
            ->table('accounts')
            ->columns('account_id', 'user_id'),
    ],
    'login' => [
        (new UniqueValidation($adapter))
            ->table('users')
            ->column('login')
            ->ignore(10),
    ],
    'phone' => [
        (new UniqueValidation($adapter))
            ->table('users')
            ->column('phone')
            ->where('is_phone_confirmed', 1),
    ],
    'product_ids' => [
        ExistsValidation::make()
            ->all(
                LookupSpec::forTable('shipment_products')
                    ->lookupColumn('product_id')
                    ->where('shipment_id', 123),
            )
            ->warmup(),
    ],
];
```

`columns()` берёт значения из массива данных по одноимённым ключам (например, `user_id`).
Если ключ не найден, будет использовано значение `null`, что трактуется как `IS NULL`.
`where()` и `whereAll()` добавляют фиксированные условия к проверке.
`ExistsValidation::all()` использует один запрос `WHERE column IN (...)` и проверяет,
что найдены все уникальные входные значения.

Если включён `warmup()`, adapter делает один запрос с выборкой строк через
`Connection::warmup()` и прогревает lifecycle-scoped keyed rows. Это не persistent cache:
данные живут вместе с объектом `Connection` и нужны для переиспользования уже полученных
строк внутри текущего lifecycle приложения.

По умолчанию warmup key строится как `connection + table + criteria columns + lookup-column`.
Для `shipment_id = 123 AND product_id IN (...)` ключом будет
`shipment_id + product_id`. Явный ключ можно задать через `warmupBy(...)` или
`LookupSpec::keyColumns(...)`; он должен включать lookup-column и все columns из fixed criteria.

Write-операции через `Connection::execute()` очищают локальный warmup store подключения,
чтобы внутри текущего lifecycle не переиспользовать устаревшие строки.
