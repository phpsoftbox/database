# PostgreSQL Full-Text Search

Компонент `Database` содержит PostgreSQL-specific API для полнотекстового поиска через `tsvector`, `tsquery`, rank и GIN-индексы.

Это не переносимый `LIKE`-поиск. API предназначен именно для PostgreSQL.

## QueryBuilder

```php
use PhpSoftBox\Database\Postgres\FullText\PgFullTextOptions;
use PhpSoftBox\Database\Postgres\FullText\PgFullTextQueryMode;

$rows = $conn->query()
    ->select('p.*')
    ->from('products p')
    ->wherePgFullText(
        'p.search_vector',
        $query,
        new PgFullTextOptions(config: 'russian', queryMode: PgFullTextQueryMode::Websearch),
    )
    ->selectPgFullTextRank('p.search_vector', $query)
    ->orderByPgFullTextRank()
    ->fetchAll();
```

Методы:

- `wherePgFullText($vector, $query, $options = null)`
- `orWherePgFullText($vector, $query, $options = null)`
- `selectPgFullTextRank($vector, $query, $alias = 'search_rank', $options = null)`
- `selectPgFullTextHeadline($document, $query, $alias = 'search_headline', $options = null, $headlineOptions = null)`
- `orderByPgFullTextRank($alias = 'search_rank', $direction = 'DESC')`

По умолчанию пустой поисковый запрос не добавляет `WHERE`. Для rank при пустом запросе добавляется `0 AS search_rank`, для headline - пустая строка.

Headline:

```php
$rows = $conn->query()
    ->select('p.*')
    ->from('products p')
    ->selectPgFullTextHeadline(
        'p.description',
        $query,
        headlineOptions: 'StartSel=<mark>, StopSel=</mark>',
    )
    ->fetchAll();
```

## Опции

```php
use PhpSoftBox\Database\Postgres\FullText\PgFullTextOptions;
use PhpSoftBox\Database\Postgres\FullText\PgFullTextQueryMode;
use PhpSoftBox\Database\Postgres\FullText\PgFullTextRankFunction;

$options = new PgFullTextOptions(
    config: 'russian',
    queryMode: PgFullTextQueryMode::Plain,
    rankFunction: PgFullTextRankFunction::Rank,
    normalization: 32,
);
```

Поддерживаемые query mode:

- `PgFullTextQueryMode::Plain` -> `plainto_tsquery`
- `PgFullTextQueryMode::Phrase` -> `phraseto_tsquery`
- `PgFullTextQueryMode::Websearch` -> `websearch_to_tsquery`

Поддерживаемые rank-функции:

- `PgFullTextRankFunction::Rank` -> `ts_rank`
- `PgFullTextRankFunction::RankCd` -> `ts_rank_cd`

## SchemaBuilder

```php
use PhpSoftBox\Database\Postgres\FullText\PgSearchVectorExpression;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

$schema->create('products', static function (TableBlueprint $table): void {
    $table->id();
    $table->text('name');
    $table->text('description')->nullable();

    $table->tsvector('natural_search_vector')->generatedAs(
        PgSearchVectorExpression::make('russian')
            ->column('name', 'A')
            ->column('description', 'B'),
    );

    $table->ginIndex(['natural_search_vector'], 'products_natural_search_gin');
});
```

Expression index:

```php
$table->ginIndexExpression(
    PgSearchVectorExpression::make('simple')
        ->column('technical_markers', 'A'),
    'products_technical_search_gin',
);
```

## Расширения PostgreSQL

```php
$schema->createExtensionIfNotExists('unaccent');
$schema->dropExtensionIfExists('unaccent');
```

`unaccent` не всегда подходит для generated columns из-за требований PostgreSQL к immutable-выражениям. Если нужен `unaccent` внутри `tsvector`, вероятно понадобится trigger-based обновление вектора отдельной миграцией.
