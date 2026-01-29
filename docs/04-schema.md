# Schema (introspection)

Для чтения схемы используется сервис `SchemaManager`.

Доступные операции (MVP):
- `tables()`
- `hasTable($table)`
- `table($table)` (полное описание таблицы)
- `columns($table)`
- `hasColumn($table, $column)`
- `missingColumns($table, $columns)`
- `primaryKey($table)`
- `indexes($table)`
- `hasIndex($table, $index)`
- `foreignKeys($table)`
- `hasForeignKey($table, $foreignKey)`
- `foreignKey($table, $foreignKey)`
- `foreignKeysByColumn($table, $column)`

Пример (SQLite):

```php
use PhpSoftBox\Database\Database;

$db = Database::fromConfig([
    'connections' => [
        'default' => 'main',
        'main' => [
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

## Helper-методы

Для точечных проверок:

```php
$schema->hasColumn('users', 'email');
$schema->hasIndex('users', 'users_email_idx');
$schema->hasForeignKey('orders', 'orders_user_fk');
```

Для массовой проверки колонок используйте `missingColumns()`:

```php
$missing = $schema->missingColumns('users', [
    'id',
    'email',
    'deleted_datetime',
]);

if ($missing->has('deleted_datetime')) {
    // колонка отсутствует
}

$missing->all(); // ['deleted_datetime']
```

`missingColumns()` читает список колонок таблицы один раз и возвращает
`MissingColumnsResult`. Результат нормализует пустые значения и дубликаты.

SQLite не отдает имя внешнего ключа через `PRAGMA foreign_key_list`, поэтому
`hasForeignKey()`/`foreignKey()` по имени полезны в первую очередь для
MariaDB/PostgreSQL. Для SQLite используйте `foreignKeysByColumn()`.

## Использование в миграциях

`SchemaManager` удобно использовать для проверок/предусловий в миграциях, а сами изменения схемы
выполняются через `SchemaBuilder` (write-подключение).
