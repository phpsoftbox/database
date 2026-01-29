# PhpSoftBox Database (DBAL)

DBAL-компонент для PhpSoftBox: единый слой работы с базой данных поверх PDO, рассчитанный как на использование без DI, так и через DI-контейнер.

## Быстрый старт

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;

$config = [
    'connections' => [
        'default' => 'main',
        'main' => [
            'dsn' => 'sqlite:///:memory:',
        ],
    ],
];

$factory = new DatabaseFactory($config);
$conn = $factory->create();

$conn->execute('CREATE TABLE ' . $conn->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);
```

## Документация

- `docs/01-configuration.md` — DSN, connections, правила default connection.
- `docs/02-usage.md` — базовое использование, DI, read/write, recipes.
- `docs/03-migrations.md` — миграции и CLI.
- `docs/04-schema.md` — schema introspection.
- `docs/05-query-builder.md` — агрегации, пагинация, настройка paginator.
- `docs/06-validator-adapter.md` — адаптер exists/unique.
- `docs/07-schema-builder.md` — SchemaBuilder, создание/изменение таблиц, внешние ключи.
