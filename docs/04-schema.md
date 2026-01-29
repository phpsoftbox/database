# Schema (introspection)

Для чтения схемы используется сервис `SchemaManager`.

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

## Использование в миграциях

`SchemaManager` удобно использовать для проверок/предусловий в миграциях, а сами изменения схемы
выполняются через `SchemaBuilder` (write-подключение).
