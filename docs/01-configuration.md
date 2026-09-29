# Конфигурация

## DSN

Поддерживаем URL-style DSN.

Примеры:
- SQLite (in-memory): `sqlite:///:memory:`
- SQLite (абсолютный путь): `sqlite:////var/app/db.sqlite`
- Postgres: `postgres://user:pass@localhost:5432/app?sslmode=disable`
- MySQL: `mysql://user:pass@localhost:3306/app?charset=utf8mb4`
- MariaDB: `mariadb://user:pass@localhost:3306/app?charset=utf8mb4`

Также поддерживаем алиасы схем:
- `pgsql://...` → `postgres://...`

Спецсимволы в логине, пароле и имени БД передаются URL-кодированными и декодируются парсером:
`postgres://app:p%40ss%3Aword@db:5432/app` → пароль `p@ss:word`.

Query-параметры DSN:
- PostgreSQL: все параметры передаются в PDO DSN как параметры libpq — `sslmode`, `sslrootcert`,
  `sslcert`, `sslkey`, `application_name`, `connect_timeout` и т.д.
  (`postgres://user:pass@db:5432/app?sslmode=require` → `pgsql:host=db;port=5432;dbname=app;sslmode=require`).
  Имя параметра — строчные латинские буквы и `_`; значение без пробелов, `;`, `'` и `\`;
  `host`/`port`/`dbname`/`user`/`password` задаются в URL, а не в query. Иначе — `ConfigurationException`.
- MySQL/MariaDB: поддерживается `charset`.

MySQL и MariaDB используют один PDO-драйвер `pdo_mysql`, но являются разными
SQL-диалектами. Выбор выполняется явно схемой DSN:

- `mysql://` выбирает `MySqlDriver`;
- `mariadb://` выбирает `MariaDbDriver`.

Фреймворк не пытается переопределить выбранный диалект через
`PDO::ATTR_DRIVER_NAME` или результат `SELECT VERSION()`.

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
