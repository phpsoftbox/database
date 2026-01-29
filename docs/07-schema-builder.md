# SchemaBuilder

`SchemaBuilder` — инструмент для создания и изменения таблиц в миграциях через объект `TableBlueprint`.

## Быстрый пример

```php
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

$schema = $db->connection('main')->schema();

$schema->create('users', function (TableBlueprint $table): void {
    $table->id();
    $table->string('email')->unique();
    $table->string('name');
    $table->datetime('created_datetime')->useCurrent();
});
```

## Типы колонок

Основные методы:
- `id()` — автоинкрементный PK.
- `string($name, $length = 255)`
- `text($name)`
- `integer($name)`
- `bigInteger($name)`
- `decimal($name, $precision = 10, $scale = 2)`
- `foreignId($name)` — семантический alias для `BIGINT UNSIGNED`, удобно для внешних ключей.
- `boolean($name)`
- `json($name)`
- `date($name)`
- `time($name)`
- `datetime($name)`
- `timestamp($name)`

## Внешние ключи

### Пример

```php
$schema->create('users', function (TableBlueprint $table): void {
    $table->id();
    $table->string('email')->unique();
});

$schema->create('profiles', function (TableBlueprint $table): void {
    $table->id();
    $table->foreignId('user_id');
    $table->string('full_name');
    $table->foreignKey(['user_id'], 'users', ['id'])->onDelete('cascade');
});
```

### API

```php
foreignKey(array $columns, string $refTable, array $refColumns, ?string $name = null): ForeignKeyBlueprint
```

Доступные действия:
- `->onDelete('cascade' | 'set null' | 'restrict' | 'no action')`
- `->onUpdate('cascade' | 'set null' | 'restrict' | 'no action')`

### Рекомендации

- Для MariaDB используйте `InnoDB`, иначе FK не будут применяться.
- Если вы хотите видеть ошибку при откате миграции, используйте `drop()` вместо `dropIfExists()`.
- `foreignId()` — предпочтительный вариант для ссылок на `id()` (BIGINT UNSIGNED).

## Удаление таблиц

```php
$schema->drop('users');        // упадет, если таблицы нет
$schema->dropIfExists('users'); // безопасный вариант
```

## Изменение таблицы (ALTER TABLE)

```php
$schema->alterTable('users', function (TableBlueprint $table): void {
    $table->dropIndex('users_legacy_email_idx');
    $table->dropColumn(['legacy_email', 'legacy_phone']);
    $table->string('email', 255)->nullable();
    $table->index(['email'], 'users_email_idx');
});
```

Поддерживаются операции:
- `dropColumn(string|array $columns)`
- `dropIndex(string|array $indexes)`
- добавление колонок через обычные типизированные методы (`string()`, `text()` и т.д.)
- добавление индексов через `index()`/`unique()` даже без операций с колонками
