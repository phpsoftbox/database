# Конфигурация

## DSN

Поддерживаем URL-style DSN.

Примеры:
- SQLite (in-memory): `sqlite:///:memory:`
- SQLite (абсолютный путь): `sqlite:////var/app/db.sqlite`
- Postgres: `postgres://user:pass@localhost:5432/app?sslmode=disable`
- MariaDB: `mariadb://user:pass@localhost:3306/app?charset=utf8mb4`

Также поддерживаем алиасы схем:
- `pgsql://...` → `postgres://...`
- `mysql://...` → `mariadb://...`

## Connections

Минимальный пример:

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;

$config = [
    'connections' => [
        'default' => 'main',
        'main' => [
            'dsn' => 'sqlite:///:memory:',
            'prefix' => 't_',
            'readonly' => false,
            'options' => [
                // PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ],
    ],
];

$factory = new DatabaseFactory($config);
$conn = $factory->create();
```

## Default connection

Если `connections.default` — строка, это имя подключения по умолчанию:

```php
return [
    'connections' => [
        'default' => 'main',
        'main' => [
            'dsn' => 'sqlite:///:memory:',
        ],
    ],
];
```

Правило:
- `connections.default` должен быть строкой (имя подключения), иначе `DatabaseFactory` и `MigrationsConfig` выбрасывают исключение.

Имя подключения `default` зарезервировано под alias, используйте другое имя (например, `main`).
