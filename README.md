# PhpSoftBox Database (DBAL)

DBAL-компонент для PhpSoftBox: единый слой работы с базой данных поверх PDO, рассчитанный как на использование без DI (через фабрику/менеджер подключений), так и через DI-контейнер.

## Цели (roadmap)
- Драйверы: SQLite, MariaDB, Postgres.
- Универсальный DSN.
- Несколько подключений (read/write), в т.ч. read-only.
- Префиксы таблиц.
- PSR-3 логирование запросов.
- Работа со схемой (introspection): таблицы/колонки/индексы/FK/PK.
- Кэширование результатов introspection.
- Транзакции.

## DSN
Поддерживаем URL-style DSN.

Примеры:
- SQLite (in-memory): `sqlite:///:memory:`
- SQLite (абсолютный путь): `sqlite:////var/app/db.sqlite`
- Postgres: `postgres://user:pass@localhost:5432/app?sslmode=disable`
- MariaDB: `mariadb://user:pass@localhost:3306/app?charset=utf8mb4`

Также поддерживаем алиасы схем (нормализуются парсером):
- `pgsql://...` → `postgres://...`
- `mysql://...` → `mariadb://...`

## Конфигурация
Фабрика принимает массив с секцией `connections`.

Минимальный пример:
- `dsn` — обязателен
- `prefix` — префикс таблиц (опционально)
- `readonly` — режим только для чтения (опционально)
- `options` — PDO options (опционально)

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;

$config = [
    'connections' => [
        'default' => [
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
$conn = $factory->create('default');
```

## Использование
```php
$conn->execute('CREATE TABLE ' . $conn->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
$conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);

$user = $conn->fetchOne('SELECT id, name FROM ' . $conn->table('users') . ' WHERE name = :name', ['name' => 'Alice']);
```

## Использование через DI

Минимальная идея для DI: регистрируем `DatabaseFactoryInterface` (с конфигом) и поверх него `ConnectionManagerInterface`.

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;

$factory = new DatabaseFactory($config);
$manager = new ConnectionManager($factory);

$conn = $manager->connection('default');
```

### Пример с PHP-DI

Ниже пример, как можно зарегистрировать DBAL и `Paginator` в контейнере PHP-DI.

> Это именно пример: вы можете хранить `$config` в отдельном файле или собирать его из env.

```php
use DI\ContainerBuilder;
use function DI\autowire;
use function DI\get;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Configurator\DatabaseFactoryInterface;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\QueryBuilder\Paginator;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    // Конфиг DBAL (пример)
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
        /** @var array $config */
        $config = get('db.config');
        return new DatabaseFactory($config);
    },

    ConnectionManagerInterface::class => autowire(ConnectionManager::class)
        ->constructor(get(DatabaseFactoryInterface::class)),

    // Дефолт для perPage задаём в одном месте
    Paginator::class => function () {
        return new Paginator(10);
    },
]);

$container = $builder->build();
```

Пример использования в сервисе:

```php
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\QueryBuilder\Paginator;

final class UsersService
{
    public function __construct(
        private readonly ConnectionManagerInterface $db,
        private readonly Paginator $paginator,
    ) {}

    public function list(int $page): array
    {
        $builder = $this->db
            ->read('main')
            ->query()
            ->select()
            ->from('users')
            ->orderBy('id', 'DESC');

        return $this->paginator->paginate($builder, page: $page);
    }
}
```

## Read-only подключения

Если у подключения выставлен флаг `readonly=true`, то:
- `fetchOne()` / `fetchAll()` разрешены
- `execute()` запрещён и выбросит исключение `ReadOnlyException`
- `transaction()` запрещён и выбросит исключение `ReadOnlyException`

Пример:

```php
$config = [
    'connections' => [
        'reporting' => [
            'dsn' => 'postgres://user:pass@localhost:5432/app',
            'readonly' => true,
        ],
    ],
];
```

## Несколько подключений: read / write

Можно описывать подключения как группы с ролями `read` и `write`:

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

$ro = $manager->read('main');   // фактически main.read
$rw = $manager->write('main');  // фактически main.write
```

Внутри `DatabaseFactory` такие подключения можно также запросить напрямую строкой:
- `create('main.read')`
- `create('main.write')`

## Рецепты (recipes)

### 1) Чтение (например, GET endpoint)

Пример: контроллер, который только читает данные.

```php
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;

final class UsersController
{
    public function __construct(
        private readonly ConnectionManagerInterface $db,
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

### 2) Запись (например, POST endpoint / service)

Пример: сервис, который пишет и использует транзакцию.

```php
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;

final class CreateUserService
{
    public function __construct(
        private readonly ConnectionManagerInterface $db,
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

### 3) Read-after-write (важно при репликации)

Если у вас read-реплика может отставать, то после записи часто нужно читать из write-подключения,
иначе можно не увидеть только что записанные данные.

```php
$conn = $this->db->write('main');

$conn->transaction(function ($conn): void {
    $conn->execute('UPDATE ' . $conn->table('users') . ' SET name = :name WHERE id = :id', [
        'id' => 10,
        'name' => 'Alice',
    ]);

    // Важно: при наличии репликации лучше читать тут же через write
    $user = $conn->fetchOne('SELECT id, name FROM ' . $conn->table('users') . ' WHERE id = :id', ['id' => 10]);
});
```

## Schema (introspection)

Для чтения схемы используется отдельный сервис `SchemaManager`.

Доступные операции (MVP):
- `tables()`
- `hasTable($table)`
- `table($table)` (полное описание таблицы)
- `columns($table)`
- `hasColumn($table, $column)`
- `primaryKey($table)`
- `indexes($table)`
- `foreignKeys($table)`

Пример (SQLite):

```php
use PhpSoftBox\Database\Database;

$db = Database::fromConfig([
    'connections' => [
        'default' => [
            'dsn' => 'sqlite:///:memory:',
        ],
    ],
]);

$db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

$schema = $db->schema('default');

$tables = $schema->tables();
$hasUsers = $schema->hasTable('users');
$pk = $schema->primaryKey('users');
$cols = $schema->columns('users');
```

### Использование в миграциях

Да — такой сервис обычно используется как часть миграций:
- `SchemaManager` даёт возможность узнать текущее состояние схемы (таблицы/колонки/индексы и т.д.)
- а сами миграции выполняют DDL/SQL через **write**-подключение (`main.write`)

На следующих итерациях можно добавить отдельный компонент для миграций (MigrationRunner),
который будет:
- хранить таблицу применённых миграций
- применять миграции в транзакции (где это возможно)
- использовать `SchemaManager` для проверок/предусловий

## Best Practices

### Read-only обычно = реплика (другой DSN)

Да, в типичной архитектуре `main.read` — это соединение к read-replica, а `main.write` — к primary.
И **в идеале это два разных DSN**, потому что это два разных хоста/инстанса.

Пример конфига:

```php
'connections' => [
    'main' => [
        'read' => [
            'dsn' => 'postgres://user:pass@replica-db:5432/app',
            'readonly' => true,
        ],
        'write' => [
            'dsn' => 'postgres://user:pass@primary-db:5432/app',
            'readonly' => false,
        ],
    ],
],
```

При этом `readonly=true` у нас выполняет роль "предохранителя" на уровне приложения: даже если DSN
по ошибке указывает на primary, код не сможет выполнить `execute()`.

## QueryBuilder

### Агрегации

`SelectQueryBuilder` поддерживает базовые агрегации:
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

### Пагинация

На уровне DBAL метод `paginate()` требует явного `perPage`.
Это сделано намеренно: DBAL не должен &laquo;угадывать&raquo; поведение на основе глобальных настроек.

```php
$result = $conn->query()
    ->select()
    ->from('users')
    ->orderBy('id', 'DESC')
    ->paginate(page: 2, perPage: 20);

// $result = [
//   'items' => [...],
//   'total' => 123,
//   'page' => 2,
//   'perPage' => 20,
//   'pages' => 7,
// ]
```

#### Дефолтный perPage через DI (Paginator)

Если нужен дефолтный `perPage`, удобно вынести его в отдельный сервис `Paginator`.
Тогда его можно сконфигурировать через DI-контейнер, или создать вручную.

```php
use PhpSoftBox\Database\QueryBuilder\Paginator;

$paginator = new Paginator(10);

$builder = $conn->query()
    ->select()
    ->from('users')
    ->orderBy('id', 'DESC');

$result = $paginator->paginate($builder, page: 2);
```
