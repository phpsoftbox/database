# Использование

## Базовое использование

```php
$conn->execute('CREATE TABLE ' . $conn->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);

$user = $conn->fetchOne(
    'SELECT id, name FROM ' . $conn->table('users') . ' WHERE name = :name',
    ['name' => 'Alice']
);
```

## Имена таблиц и экранирование

`table()` только добавляет prefix подключения:

```php
$conn->table('users'); // app_users
```

Для raw SQL, где имя таблицы нужно экранировать под текущий драйвер, используйте `quoteTable()`:

```php
$conn->quoteTable('users');        // `app_users` для MariaDB, "app_users" для PostgreSQL/SQLite
$conn->quoteTable('public.users'); // "public"."app_users" для PostgreSQL
```

`quoteTable()` работает с логическим именем таблицы, поэтому prefix добавляется автоматически.
Для колонок, индексов и constraint-имен используйте `quoteIdentifier()`; он экранирует ровно
переданный идентификатор и не добавляет prefix.

Примечание: API принимает named-параметры (`:name`). При выполнении запроса внутри `Connection` они могут быть автоматически преобразованы в positional (`?`) для драйвера PDO.

## Использование через DI

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;

$factory = new DatabaseFactory($config);
$manager = new ConnectionManager($factory);

$conn = $manager->connection();
```

### Пример с PHP-DI

```php
use DI\ContainerBuilder;
use function DI\autowire;
use function DI\get;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Configurator\DatabaseFactoryInterface;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    'db.config' => [
        'connections' => [
            'main' => [
                'write' => [
                    'dsn' => 'postgres://user:pass@primary-db:5432/app',
                    'readonly' => false,
                ],
                'read' => [
                    'dsn' => 'postgres://user:pass@replica-db:5432/app',
                    'readonly' => true,
                ],
            ],
        ],
    ],

    DatabaseFactoryInterface::class => function () {
        $config = get('db.config');
        return new DatabaseFactory($config);
    },

    ConnectionManagerInterface::class => autowire(ConnectionManager::class)
        ->constructor(get(DatabaseFactoryInterface::class)),
]);
```

## Read-only подключения

Если у подключения выставлен флаг `readonly=true`, то:
- `fetchOne()` / `fetchAll()` разрешены
- `execute()` запрещён и выбросит исключение `ReadOnlyException`
- `transaction()` запрещён и выбросит исключение `ReadOnlyException`

Пример:

```php
return [
    'connections' => [
        'reporting' => [
            'dsn' => 'postgres://user:pass@localhost:5432/app',
            'readonly' => true,
        ],
    ],
];
```

## Несколько подключений: read / write

```php
$config = [
    'connections' => [
        'main' => [
            'read' => [
                'dsn' => 'postgres://user:pass@localhost:5432/app',
                'readonly' => true,
            ],
            'write' => [
                'dsn' => 'postgres://user:pass@localhost:5432/app',
                'readonly' => false,
            ],
        ],
    ],
];

$factory = new DatabaseFactory($config);
$manager = new ConnectionManager($factory);

$ro = $manager->read('main');
$rw = $manager->write('main');
```

Если вызвать `DatabaseFactory::create('main')` без роли, будет использовано `main.write` (если он есть).

## Recipes

### Чтение (GET)

```php
final class UsersController
{
    public function __construct(
        private readonly \PhpSoftBox\Database\Connection\ConnectionManagerInterface $db,
    ) {}

    public function list(): array
    {
        $conn = $this->db->read('main');

        return $conn->fetchAll(
            'SELECT id, name FROM ' . $conn->table('users') . ' ORDER BY id DESC'
        );
    }
}
```

### Запись (POST)

```php
final class CreateUserService
{
    public function __construct(
        private readonly \PhpSoftBox\Database\Connection\ConnectionManagerInterface $db,
    ) {}

    public function create(string $name): void
    {
        $conn = $this->db->write('main');

        $conn->transaction(function ($conn) use ($name): void {
            $conn->execute(
                'INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)',
                ['name' => $name]
            );
        });
    }
}
```

### Read-after-write

```php
$conn = $this->db->write('main');

$conn->transaction(function ($conn): void {
    $conn->execute('UPDATE ' . $conn->table('users') . ' SET name = :name WHERE id = :id', [
        'id' => 10,
        'name' => 'Alice',
    ]);

    $user = $conn->fetchOne('SELECT id, name FROM ' . $conn->table('users') . ' WHERE id = :id', ['id' => 10]);
});
```

## Транзакции и уровень изоляции

```php
use PhpSoftBox\Database\IsolationLevelEnum;

$conn->transaction(function ($conn): void {
    // ...
}, IsolationLevelEnum::REPEATABLE_READ);
```

Уровень изоляции применяется только к внешней транзакции (вложенные используют `SAVEPOINT`):

- MySQL/MariaDB: `SET TRANSACTION ISOLATION LEVEL ...` выполняется **до** `BEGIN` и действует
  на одну следующую транзакцию (внутри начатой транзакции MySQL/MariaDB запрещают менять уровень);
- PostgreSQL: `SET TRANSACTION ISOLATION LEVEL ...` выполняется первым оператором внутри транзакции;
- SQLite: `PRAGMA read_uncommitted`.

Ошибки PDO при старте транзакции и создании savepoint выбрасываются как `QueryException`.

## Типы параметров

Параметры связываются через `PDOStatement::bindValue()`:

- `bool` — для PostgreSQL как `PDO::PARAM_BOOL` (`true`/`false` можно передавать в boolean-колонки
  и условия), для MySQL/MariaDB и SQLite как `1`/`0`;
- `null` — `PDO::PARAM_NULL`;
- `DateTimeInterface` — строка `Y-m-d H:i:s` для MySQL/MariaDB и ISO 8601 (ATOM) для остальных;
- ресурсы (потоки) — `PDO::PARAM_LOB`;
- остальные значения — строкой (`PDO::PARAM_STR`).
