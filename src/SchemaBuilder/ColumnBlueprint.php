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
     * Применимо в основном к datetime/timestamp у MySQL/MariaDB.
     */
    public bool $useCurrent         = false;
    public bool $useCurrentOnUpdate = false;

    /**
     * Формат для useCurrent/useCurrentOnUpdate.
     */
    public UseCurrentFormatsEnum $useCurrentFormat = UseCurrentFormatsEnum::DATETIME;

    /**
     * Индекс на колонку.
     */
    public ?string $indexName = null;

    /**
     * Уникальный индекс на колонку.
     */
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

    public function useCurrent(bool $value = true, UseCurrentFormatsEnum $format = UseCurrentFormatsEnum::DATETIME): self
    {
        $this->useCurrent       = $value;
        $this->useCurrentFormat = $format;

        return $this;
    }

    public function useCurrentOnUpdate(bool $value = true, UseCurrentFormatsEnum $format = UseCurrentFormatsEnum::DATETIME): self
    {
        $this->useCurrentOnUpdate = $value;
        $this->useCurrentFormat   = $format;

        return $this;
    }

    public function index(?string $name = null): self
    {
        $this->indexName = $name;

        return $this;
    }

    public function unique(?string $name = null): self
    {
        $this->uniqueName = $name;

        return $this;
    }

    public function change(bool $value = true): self
    {
        $this->change = $value;

        return $this;
    }
}
