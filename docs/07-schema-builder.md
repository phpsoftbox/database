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

## Кодировка и правила сравнения колонок

`charset()` задаёт кодировку текста, а `collation()` — правила его сравнения
и сортировки. Настройки таблицы служат значениями по умолчанию; отдельная колонка
может их переопределить. Это влияет и на уникальные индексы.

Для MySQL и MariaDB модификаторы поддерживаются у `string()` и `text()` при
CREATE TABLE, ADD COLUMN и MODIFY COLUMN (`change()`):

```php
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

$schema->create('external_codes', function (TableBlueprint $table): void {
    $table->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
    $table->id()->comment('Идентификатор');
    $table->string('group_code', 32)->comment('Группа кодов');
    $table->string('serial_number', 64)
        ->charset('utf8mb4')->collation('utf8mb4_bin')
        ->comment('Регистрозависимый серийный номер');
    $table->text('raw_value')->nullable()
        ->charset('utf8mb4')->collation('utf8mb4_bin')
        ->comment('Исходное значение');
    $table->string('label')->comment('Обычное название');
    $table->unique(['group_code', 'serial_number'], 'external_codes_group_serial_unique');
});
```

`serial_number` и `raw_value` используют `utf8mb4_bin`, а `label` наследует
`utf8mb4_unicode_ci`. При одинаковой группе значения `AbC` и `abc` допустимы
одновременно; повтор точно такого же серийного номера нарушит unique.
Глобальные настройки подключения при этом не меняются.

### Опции можно задавать независимо

- Обе опции: используются указанные charset и collation.
- Только `charset()`: СУБД выбирает default collation этой кодировки, которая
  может отличаться от collation таблицы и зависит от сервера.
- Только `collation()`: СУБД определяет связанную с ней кодировку.
- Опции не заданы: builder не добавляет соответствующие SQL-фрагменты.

Компонент не проверяет списки кодировок на сервере и не подбирает замену.
Неизвестное имя или несовместимая пара приводят к ошибке СУБД, доступной как
`QueryException`. Например, `latin1` вместе с `utf8mb4_bin` не исправляется автоматически.

### Изменение существующей колонки

```php
$schema->alterTable('external_codes', function (TableBlueprint $table): void {
    $table->string('serial_number', 64)
        ->charset('utf8mb4')->collation('utf8mb4_bin')
        ->comment('Регистрозависимый серийный номер')
        ->change();
});
```

В `change()` указывается полное итоговое определение: повторите длину,
`nullable()`, `default()`, комментарий, generated-выражение и другие атрибуты,
которые должны сохраниться. Builder не считывает старое определение из БД
и не восстанавливает пропущенные модификаторы. Индекс не нужно объявлять повторно,
если он уже существует.

Charset/collation допустимы и для текстовых generated columns: в SQL они идут
после типа и длины, перед `GENERATED ALWAYS AS`. Ограничения конкретной СУБД
на вычисляемые колонки продолжают действовать.

Смена только collation внутри `utf8mb4` не должна переписывать исходные значения.
Не путайте её с конвертацией кодировки: изменение самого charset может изменить
представление данных и имеет ограничения СУБД. ALTER также может перестроить
индексы/таблицу и потребовать блокировок — builder не гарантирует online-операцию.

### Ограничения и ошибки конфигурации

- Поддерживаются только `string` и `text` на MySQL/MariaDB. Опции на `id`, числах,
  датах, JSON и других типах вызывают `ConfigurationException` до выполнения SQL.
- PostgreSQL и SQLite сейчас явно отклоняют **колоночные** charset/collation,
  включая `change()`. Это ограничение SchemaBuilder, а не утверждение об отсутствии
  поддержки COLLATE в этих СУБД. Поведение табличных опций на них не расширяется.
- Имена состоят из ASCII-букв, цифр и `_`. Обычные пробелы по краям удаляются;
  пустые строки, кавычки, управляющие символы и SQL-фрагменты запрещены.
  Имена не нужно предварительно заключать в кавычки.
- Проверяются и fluent-вызовы, и публичные свойства blueprint при компиляции.
  Для табличных charset/collation используется та же проверка имён.

`utf8mb4_bin` обеспечивает регистрозависимость, но **не является обещанием
побайтовой уникальности**: его семантика PAD SPACE может игнорировать конечные
пробелы при сравнении. Если они значимы для идентификатора, отдельно выбирайте
подходящую collation/тип хранения для целевой СУБД.

Семантика описана в документации [MySQL — Column Character Set and Collation](https://dev.mysql.com/doc/refman/8.4/en/charset-column.html),
[MySQL — binary и _bin](https://dev.mysql.com/doc/refman/8.4/en/charset-binary-collations.html)
и [MariaDB — Character Sets and Collations](https://mariadb.com/docs/server/reference/data-types/string-data-types/character-sets/setting-character-sets-and-collations).

### Обновление потребителей

Ранее модификаторы колонок сохранялись в blueprint, но не попадали в SQL.
Теперь они применяются; миграции с некорректными настройками перестают молча
проходить. `charset('')` и `collation('')` на колонке или таблице тоже вызывают
исключение вместо прежнего игнорирования. Не вызывайте модификатор, если опции нет.

После обновления зависимости ручной ALTER можно заменить декларативным builder.
Изменение базовой миграции не обновляет уже созданные БД: им нужна отдельная
миграция изменения схемы. Порядок удаления временных миграций, история их выполнения
и публикация пакета остаются отдельными задачами приложения/релиза.

Для проверки итоговой схемы используйте `information_schema.COLUMNS`:
`CHARACTER_SET_NAME`, `COLLATION_NAME`, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`
и `COLUMN_COMMENT`. `SHOW FULL COLUMNS` показывает collation, но не отдельное поле charset.

## Вычисляемые колонки

`generatedAs()` описывает колонку, значение которой вычисляет СУБД:

```php
$schema->create('order_lines', function (TableBlueprint $table): void {
    $table->integer('price');
    $table->integer('quantity');
    $table->integer('total')
        ->generatedAs('price * quantity');
});
```

Второй аргумент выбирает способ вычисления:

```php
// Значение вычисляется при записи и хранится в таблице.
$table->integer('stored_total')
    ->generatedAs('price * quantity', stored: true);

// Значение вычисляется при чтении.
$table->integer('virtual_total')
    ->generatedAs('price * quantity', stored: false);
```

Поддержка зависит от СУБД и её версии:

- MariaDB и MySQL: `STORED` и `VIRTUAL` для `CREATE TABLE`, `ADD COLUMN`
  и `MODIFY COLUMN`, с отдельной driver-specific компиляцией;
- PostgreSQL: компилируются `STORED` и `VIRTUAL`; virtual generated columns
  требуют PostgreSQL 18+, а изменение выражения через `change()` —
  PostgreSQL 17+;
- SQLite: SchemaBuilder пока явно отклоняет `generatedAs()`.

MariaDB-компилятор не добавляет `NULL`/`NOT NULL` к generated-колонкам.
MySQL-компилятор сохраняет запрошенную nullability, поскольку MySQL поддерживает
эти атрибуты.

MySQL также не поддерживает `CREATE INDEX IF NOT EXISTS`, поэтому его компилятор
не добавляет эту часть выражения. MariaDB-компилятор продолжает использовать
`IF NOT EXISTS`.

SchemaBuilder намеренно не запрашивает версию сервера. Использование более старой
версии PostgreSQL не запрещается: если миграция запросит отсутствующую в ней
возможность, PostgreSQL вернёт обычную ошибку выполнения DDL.

Generated-колонка не может использовать:

- `default()`, включая `default(null)`;
- `autoIncrement()`;
- `useCurrent()`;
- `useCurrentOnUpdate()`.

Пустое выражение также считается ошибкой конфигурации. Несовместимые комбинации
завершаются `ConfigurationException` до выполнения SQL.

Generated-колонки могут участвовать в индексах:

```php
$table->boolean('active_marker')
    ->nullable()
    ->generatedAs('IF(deleted_datetime IS NULL, 1, NULL)')
    ->unique('memberships_active_unique');
```

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
    $table->renameColumn('display_name', 'name');
    $table->string('email', 255)->nullable();
    $table->string('name', 320)->nullable(false)->change();
    $table->index(['email'], 'users_email_idx');
});
```

Поддерживаются операции:
- `dropColumn(string|array $columns)`
- `dropIndex(string|array $indexes)`
- `renameColumn(string $from, string $to)`
- добавление колонок через обычные типизированные методы (`string()`, `text()` и т.д.)
- изменение существующих колонок через `->change()`
- добавление индексов через `index()`/`unique()` даже без операций с колонками

`renameColumn()` компилируется в `ALTER TABLE ... RENAME COLUMN ... TO ...`.
Имя таблицы получает prefix подключения через `Connection::table()`, имена колонок
экранируются компилятором под текущий драйвер.

`change()` описывает новое целевое состояние колонки:

```php
$schema->alterTable('users', function (TableBlueprint $table): void {
    $table->string('email', 320)
        ->nullable(false)
        ->default('unknown@example.test')
        ->change();
});
```

Для MariaDB генерируется `ALTER TABLE ... MODIFY COLUMN ...`.
Для PostgreSQL генерируется набор `ALTER COLUMN`: `DROP DEFAULT`, `TYPE`,
`SET/DROP NOT NULL`, затем `SET DEFAULT`, если default задан.

Важно: `change()` не пытается прочитать текущее состояние колонки из БД.
Если не вызвать `nullable()`, колонка будет описана как `NOT NULL`. Если не
задать `default()`, для PostgreSQL будет сгенерирован `DROP DEFAULT`.

### Изменение автоинкрементного ID в MySQL/MariaDB

Для существующего первичного ключа можно использовать `id()->change()`:

```php
$schema->alterTable('users', static function (TableBlueprint $table): void {
    $table->id()->comment('Идентификатор пользователя')->change();
});
```

Колонка получит определение `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT`.
Существующий первичный ключ сохраняется самой БД: повторное объявление
`PRIMARY KEY` в `MODIFY COLUMN` не добавляется. При создании таблицы или добавлении
новой колонки `id()` по-прежнему объявляет первичный ключ.

То же целевое определение колонки можно задать явно:

```php
$schema->alterTable('users', static function (TableBlueprint $table): void {
    $table->bigInteger('id')
        ->unsigned()
        ->autoIncrement()
        ->comment('Идентификатор пользователя')
        ->change();
});
```

`autoIncrement()` добавляет `AUTO_INCREMENT`, но не создаёт первичный ключ или
индекс. Для существующей колонки сохраняются её индексы; перед включением
автоинкремента на другой колонке нужно обеспечить индекс, требуемый БД.

Это не автоматическое сохранение всех прежних атрибутов: `change()` описывает
полное новое определение. Если использовать `bigInteger()->change()` без
`autoIncrement()`, MySQL/MariaDB снимет автоинкремент. Аналогично явно указывайте
`unsigned()`, комментарий и другие атрибуты, которые должны остаться.

`first()` и `after()` поддерживаются только MariaDB/MySQL. PostgreSQL не
поддерживает позиционирование колонок в `ADD COLUMN`; новые колонки добавляются
в конец таблицы, поэтому эти модификаторы там игнорируются.
