<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

interface SchemaBuilderInterface
{
    /**
     * Создаёт таблицу.
     *
     * @param callable(TableBlueprint):void $definition
     * @param bool $ifNotExists Если true, DDL должен игнорировать ошибку при существующей таблице.
     */
    public function create(string $table, callable $definition, bool $ifNotExists = true): void;

    /**
     * Создаёт таблицу, если её ещё нет.
     *
     * @param callable(TableBlueprint):void $definition
     */
    public function createIfNotExists(string $table, callable $definition): void;

    /**
     * Изменяет таблицу (ALTER TABLE ...).
     *
     * @param callable(TableBlueprint):void $definition
     */
    public function alterTable(string $table, callable $definition): void;

    /**
     * Добавляет колонку в таблицу.
     *
     * Это convenience-обёртка над alterTable().
     *
     * @param callable(TableBlueprint):void $definition
     */
    public function addColumn(string $table, callable $definition): void;

    /**
     * Удаляет таблицу, если она существует.
     */
    public function dropIfExists(string $table): void;

    /**
     * Удаляет таблицу (ошибка, если таблицы нет).
     */
    public function drop(string $table): void;

    /**
     * Переименовывает таблицу.
     */
    public function renameTable(string $from, string $to): void;
}
