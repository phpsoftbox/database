# Заполнение БД через CLI

Seed-сценарий заполняет справочники, начальные настройки или демонстрационные данные в БД с уже подготовленной схемой.

```bash
php psb db:seed reference-data
php psb db:seed reference-data --connection=core
```

Команда использует подключение для записи выбранной группы. Если `--connection` опущен, используется `default` из конфигурации БД.

## Регистрация сценариев

Добавьте класс в автозагрузку приложения и реализуйте
`PhpSoftBox\Database\Seed\SeederInterface::run(ConnectionInterface $connection): void`.
Все операции записи выполняйте через полученное подключение.

Например, в приложении с контейнером PhpSoftBox зарегистрируйте сценарии в `config/definitions/seeds.php`:

```php
<?php

declare(strict_types=1);

use App\Database\Seeds\ReferenceDataSeeder;
use App\Database\Seeds\TenantDefaultsSeeder;
use PhpSoftBox\Database\Seed\SeedRunner;
use Psr\Container\ContainerInterface;

use function PhpSoftBox\Container\factory;

return [
    SeedRunner::class => factory(static fn (ContainerInterface $container): SeedRunner => new SeedRunner(
        $container,
        [
            'reference-data'  => ['service' => ReferenceDataSeeder::class, 'scope' => 'both'],
            'tenant-defaults' => ['service' => TenantDefaultsSeeder::class, 'scope' => 'tenant'],
        ],
    )),
];
```

`service` — идентификатор класса или сервиса в DI-контейнере. `scope` задаёт допустимые команды:

- `core` — только `db:seed`
- `tenant` — только `tenant:db:seed` из пакета `phpsoftbox/multi-tenant`
- `both` — обе команды

Команда запускает сценарий в транзакции на переданном подключении. Если сценарий выбрасывает исключение, команда
возвращает ненулевой код, а записи в этой транзакции откатываются. Изменения схемы оформляйте миграциями.

Сценарий выполняется при каждом вызове. Для справочников и начальных настроек используйте стабильные уникальные ключи
и операции вставки с обновлением либо пропуском существующей строки. Однократные изменения данных, привязанные к версии
схемы, оформляйте миграциями.
