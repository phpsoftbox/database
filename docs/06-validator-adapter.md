# Валидация exists/unique

Можно использовать адаптер для правил `exists`/`unique` из компонента Validator.

```php
use PhpSoftBox\Database\Validator\DatabaseValidationAdapter;
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
];
```

`columns()` берёт значения из массива данных по одноимённым ключам (например, `user_id`).
Если ключ не найден, будет использовано значение `null`, что трактуется как `IS NULL`.
