# Strict `select()/where()/having()` и raw API

## Что изменилось

- `where(string)`/`orWhere(string)` теперь принимают только простые условия.
- `having(string)`/`orHaving(string)` теперь принимают только простые условия.
- `select(string)` теперь принимает только простые колонки.
- Для сложных выражений добавлены и обязательны:
  - `selectRaw()` (или `new Expression(...)`)
  - `whereRaw()` / `orWhereRaw()`
  - `havingRaw()` / `orHavingRaw()`

Дополнительно:
- `SelectQueryBuilder::notExists()` добавлен как инверсия `exists()`.
- Расширен DSL `where(array $conditions)`.

## Что считается сложным SQL

В `select(string)` запрещены:
- функции/выражения со скобками (`COUNT(...)`, `COALESCE(...)`, `CASE ...`)
- перечисление через запятую в одной строке
- SQL-ключевые слова (`SELECT`, `EXISTS`, `CASE`, ...)

В `where(string)`/`having(string)` теперь запрещены:
- скобки `(` / `)`
- ключевые слова `AND`, `OR`, `SELECT`, `EXISTS`, `CASE`, `WHEN`, `THEN`, `ELSE`, `END`, `UNION`, `INTERSECT`, `EXCEPT`

Такие условия нужно переносить в `*Raw()` или в специализированные методы:
- `whereExists()` / `whereNotExists()`
- `whereIn()` / `whereNotIn()`
- группировки через `where(function (...) {})`

## Примеры миграции

### Было

```php
$qb->select('COUNT(*) AS total');
```

### Стало

```php
$qb->selectRaw('COUNT(*) AS total');
// или
$qb->select(new Expression('COUNT(*) AS total'));
```

### Было

```php
$qb->where('EXISTS (SELECT 1 FROM orders o WHERE o.user_id = u.id)');
```

### Стало

```php
$qb->whereExists(static function ($q): void {
    $q->select('1')
      ->from('orders o')
      ->where('o.user_id = u.id');
});
```

### Было

```php
$qb->where('(status = :pending OR status = :pending_amount)', [
    'pending' => 'pending',
    'pending_amount' => 'pending_amount',
]);
```

### Стало

```php
$qb->where(function ($q): void {
    $q->where('status = :pending', ['pending' => 'pending'])
      ->orWhere('status = :pending_amount', ['pending_amount' => 'pending_amount']);
});
```

### Было

```php
$qb->having('COUNT(*) > :min', ['min' => 10]);
```

### Стало

```php
$qb->havingRaw('COUNT(*) > :min', ['min' => 10]);
```
