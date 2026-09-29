<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Exception\ConfigurationException;
use Stringable;

use function trim;

/**
 * Чертёж колонки.
 *
 * Хранит тип колонки и её модификаторы/опции.
 *
 * Важно: поведение driver-specific модификаторов определяет SQL-компилятор.
 * Schema-critical модификаторы не должны игнорироваться молча.
 */
class ColumnBlueprint
{
    public ?int $length     = null;
    public ?int $precision  = null;
    public ?int $scale      = null;
    public bool $nullable   = false;
    public bool $unsigned   = false;
    public mixed $default   = null;
    public bool $hasDefault = false;
    public ?string $comment = null;

    public ?string $generatedExpression = null;
    public bool $generatedStored        = true;

    /**
     * MySQL/MariaDB: позиционирование колонки.
     */
    public bool $isFirst        = false;
    public ?string $afterColumn = null;

    /**
     * Автоинкремент.
     *
     * Важно: для create table это часто часть определения id.
     * Для alter table поддержку и правила контролирует компилятор.
     */
    public bool $autoIncrement     = false;
    public ?int $autoIncrementFrom = null;

    /**
     * Кодировка/сравнение. Обычно применимо к string/text на MySQL/MariaDB.
     */
    public ?string $charset   = null;
    public ?string $collation = null;

    /**
     * CURRENT_TIMESTAMP по умолчанию / ON UPDATE.
     *
     * Применимо только к datetime/timestamp. ON UPDATE поддерживают только MySQL/MariaDB.
     */
    public bool $useCurrent         = false;
    public bool $useCurrentOnUpdate = false;

    /**
     * Формат для useCurrent/useCurrentOnUpdate.
     *
     * @deprecated Не влияет на SQL: CURRENT_TIMESTAMP применяется к любой колонке datetime/timestamp.
     */
    public UseCurrentFormatsEnum $useCurrentFormat = UseCurrentFormatsEnum::DATETIME;

    /**
     * Индекс на колонку: флаг и необязательное имя (без имени используется {table}_{column}_index).
     */
    public bool $withIndex    = false;
    public ?string $indexName = null;

    /**
     * Уникальный индекс на колонку: флаг и необязательное имя (без имени используется {table}_{column}_unique).
     */
    public bool $withUnique    = false;
    public ?string $uniqueName = null;

    /**
     * ALTER TABLE: изменить существующую колонку вместо добавления новой.
     */
    public bool $change = false;

    public function __construct(
        public readonly string $name,
        public string $type,
    ) {
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;

        return $this;
    }

    public function unsigned(bool $value = true): self
    {
        $this->unsigned = $value;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default    = $value;
        $this->hasDefault = true;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function generatedAs(string|Stringable $expression, bool $stored = true): self
    {
        $expression = trim((string) $expression);
        if ($expression === '') {
            throw new ConfigurationException('Generated column expression must be non-empty.');
        }

        $this->generatedExpression = $expression;
        $this->generatedStored     = $stored;

        return $this;
    }

    public function first(): self
    {
        $this->isFirst     = true;
        $this->afterColumn = null;

        return $this;
    }

    public function after(string $column): self
    {
        $column = trim($column);
        if ($column === '') {
            return $this;
        }
        $this->afterColumn = $column;
        $this->isFirst     = false;

        return $this;
    }

    public function autoIncrement(int $from = 1): self
    {
        $this->autoIncrement     = true;
        $this->autoIncrementFrom = $from;

        return $this;
    }

    public function autoIncrementFrom(int $from): self
    {
        $this->autoIncrement     = true;
        $this->autoIncrementFrom = $from;

        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = CharsetCollationName::normalize($charset, 'charset');

        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = CharsetCollationName::normalize($collation, 'collation');

        return $this;
    }

    public function datetime(): self
    {
        $this->type = 'datetime';

        return $this;
    }

    public function timestamp(): self
    {
        $this->type = 'timestamp';

        return $this;
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP для колонки datetime/timestamp (MySQL/MariaDB, PostgreSQL, SQLite).
     *
     * Аргумент $format сохранён для совместимости и не влияет на SQL.
     */
    public function useCurrent(bool $value = true, UseCurrentFormatsEnum $format = UseCurrentFormatsEnum::DATETIME): self
    {
        $this->useCurrent       = $value;
        $this->useCurrentFormat = $format;

        return $this;
    }

    /**
     * ON UPDATE CURRENT_TIMESTAMP для колонки datetime/timestamp. Поддерживается только MySQL/MariaDB;
     * компиляторы PostgreSQL и SQLite выбрасывают ConfigurationException.
     *
     * Аргумент $format сохранён для совместимости и не влияет на SQL.
     */
    public function useCurrentOnUpdate(bool $value = true, UseCurrentFormatsEnum $format = UseCurrentFormatsEnum::DATETIME): self
    {
        $this->useCurrentOnUpdate = $value;
        $this->useCurrentFormat   = $format;

        return $this;
    }

    /**
     * Создаёт индекс на колонку. Без имени используется имя по умолчанию {table}_{column}_index.
     */
    public function index(?string $name = null): self
    {
        $this->withIndex = true;
        $this->indexName = $name;

        return $this;
    }

    /**
     * Создаёт уникальный индекс на колонку. Без имени используется имя по умолчанию {table}_{column}_unique.
     */
    public function unique(?string $name = null): self
    {
        $this->withUnique = true;
        $this->uniqueName = $name;

        return $this;
    }

    public function change(bool $value = true): self
    {
        $this->change = $value;

        return $this;
    }
}
