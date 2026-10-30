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
- `db:migrate:dump` — записать снимок схемы для развёртывания пустой базы
- `db:migrate:publish` — скопировать миграции из `vendor/*/*/database/migrations` в приложение

Опции:
- `--connection` (`-c`) — имя подключения/группы (по умолчанию `migrations.default`)
- `--path` (`-p`) — относительный путь внутри `migrations.basePath`
- `--steps` (`-s`) — количество шагов для rollback (по умолчанию `1`)
- `--package` — для `db:migrate:publish`: опубликовать миграции только одного пакета, например `phpsoftbox/auth`
- `--vendor` — для `db:migrate:publish`: путь к `vendor`, если команда запущена не из корня приложения
- `--force` (`-f`) — для `db:migrate:publish`: перезаписать уже опубликованные файлы
- `--data` (`-d`) — для `db:migrate:dump`: таблицы через запятую, строки которых сохраняются в снимке

Миграции ищутся только в корне указанной директории (без рекурсии).
При программной загрузке `FileMigrationLoader::load($dir, recursive: true)` миграции из подкаталогов
упорядочиваются по имени файла (`YYYYMMDDHHMMSS_description`), а не по пути: подкаталог не меняет хронологию.

## Снимок схемы

Новая установка не обязана проходить всю историю миграций: `db:migrate:dump` записывает
`schema.sql` в корень каталога миграций подключения. Снимок снимается с базы, где применены все
миграции каталога, и хранит драйвер, prefix, список покрытых миграций и SQL создания таблиц.

`db:migrate` использует снимок так:

- база пустая (нет применённых миграций и таблиц подключения, кроме `migrations`) — схема создаётся
  из снимка, покрытые миграции отмечаются применёнными, затем применяются более новые миграции;
- база уже установлена — снимок не загружается, миграции применяются обычным путём;
- в установленной базе нет миграции, которая покрыта снимком и удалена из каталога, — команда
  останавливается: такую базу нужно сначала обновить версией приложения, где эта миграция ещё есть.

Поэтому файлы старых миграций можно удалить только после того, как их получили все установки.
Снимок не используется при запуске с `--path` и при откате. Поддерживаются MySQL, MariaDB и SQLite;
представления и триггеры в снимок не входят. Счётчики AUTO_INCREMENT не сохраняются, а после
вставки строк из снимка продолжаются с них.

Строки таблиц попадают в снимок только по `--data` (при повторном снимке список берётся из
прежнего файла). Указывайте только постоянные справочники, которые миграции заполняют одинаково
везде: валюты, роли, права. Строки со значениями конкретной установки (случайные UUID, время
установки) в снимок не включаются — иначе все новые установки получат одно и то же значение;
такие строки создаёт приложение при установке.

```
php psb db:migrate:dump --data=currencies,roles,permissions
```

## Таблица применённых миграций

`SqlMigrationRepository` создаёт таблицу `migrations` (с prefix подключения) через
`SchemaBuilder::createIfNotExists()` один раз на подключение: повторные вызовы `ensureTable()` из
`appliedIds()`/`markApplied()`/`removeApplied()` (в том числе внутри транзакции миграции) DDL не выполняют.
Для существующей таблицы индексы повторно не создаются — это важно для MySQL, где нет
`CREATE INDEX IF NOT EXISTS` и повторный запуск раньше падал с ошибкой 1061.

При runtime-ошибках в `db:migrate` и `db:migrate:rollback` в CLI выводится полный stack-trace исключения.

## Примеры

Создать миграцию:

```
php psb db:migrate:make create_users_table
```

Применить миграции:

```
php psb db:migrate --connection=main
```

Опубликовать миграции пакета:

```
php psb db:migrate:publish --package=phpsoftbox/auth
php psb db:migrate:publish --package=phpsoftbox/session
```

Без `--package` команда просканирует все пакеты в `vendor` и скопирует найденные
миграции в путь, настроенный для выбранного подключения.
