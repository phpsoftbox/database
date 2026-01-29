<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Schema;

/**
 * Контракт менеджера схемы (introspection).
 *
 * Важно: это DBAL-уровень (чтение схемы), не ORM.
 */
interface SchemaManagerInterface
{
    /**
     * Возвращает список таблиц.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * Проверяет, существует ли таблица.
     */
    public function hasTable(string $table): bool;

    /**
     * Возвращает полное описание таблицы (колонки/индексы/FK).
     */
    public function table(string $table): TableDefinition;

    /**
     * Возвращает описание колонок таблицы.
     *
     * @return list<ColumnDefinition>
     */
    public function columns(string $table): array;

    /**
     * Проверяет, существует ли колонка в таблице.
     */
    public function hasColumn(string $table, string $column): bool;

    /**
     * Возвращает имена колонок, входящих в первичный ключ (в порядке).
     *
     * @return list<string>
     */
    public function primaryKey(string $table): array;

    /**
     * Возвращает индексы таблицы.
     *
     * @return list<IndexDefinition>
     */
    public function indexes(string $table): array;

    /**
     * Возвращает внешние ключи таблицы.
     *
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeys(string $table): array;

    /**
     * Возвращает описание колонки или null.
     */
    public function column(string $table, string $column): ?ColumnDefinition;

    /**
     * Возвращает описание индекса или null.
     */
    public function index(string $table, string $index): ?IndexDefinition;

    /**
     * Возвращает внешние ключи, которые исходят из указанной колонки.
     *
     * @return list<ForeignKeyDefinition>
     */
    public function foreignKeysByColumn(string $table, string $column): array;
}
