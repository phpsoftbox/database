# Миграции

## Конфигурация

```php
return [
    'connections' => [
        'default' => 'main',
        'main' => [
            'read' => ['dsn' => 'postgres://user:pass@ro-host:5432/app', 'readonly' => true],
            'write' => ['dsn' => 'postgres://user:pass@rw-host:5432/app', 'readonly' => false],
        ],
    ],
    'migrations' => [
        'default' => 'main',
        'basePath' => 'database/migrations',
        'paths' => [
            // 'main' => 'database/migrations/custom-main',
        ],
    ],
];
```

Правила:
- `migrations.basePath` — базовая директория миграций.
- `migrations.default` — имя подключения по умолчанию.
- Если `migrations.default` не задан, используется `connections.default` (строка).
- `migrations.paths` — опциональные переопределения пути по имени подключения.

## CLI-команды

Регистрация провайдера команд:

```php
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PhpSoftBox\Database\Cli\DatabaseCommandProvider;
use PhpSoftBox\Database\Migrations\MigrationsConfig;

$registry = new InMemoryCommandRegistry();
$registry->addProvider(DatabaseCommandProvider::class);

// В DI-контейнер также нужно зарегистрировать MigrationsConfig:
// $container->set(MigrationsConfig::class, new MigrationsConfig($basePath, $paths, $defaultConnection));
```

Команды:
- `db:migrate` — применить миграции
- `db:migrate:rollback` — откатить миграции
- `db:migrate:make` — создать файл миграции

Опции:
- `--connection` (`-c`) — имя подключения/группы (по умолчанию `migrations.default`)
- `--path` (`-p`) — относительный путь внутри `migrations.basePath`
- `--steps` (`-s`) — количество шагов для rollback (по умолчанию `1`)

Миграции ищутся только в корне указанной директории (без рекурсии).

## Примеры

Создать миграцию:

```
php psb db:migrate:make create_users_table
```

Применить миграции:

```
php psb db:migrate --connection=main
```
