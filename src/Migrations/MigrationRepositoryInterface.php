<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

interface MigrationRepositoryInterface
{
    /**
     * Создаёт таблицу миграций, если её нет.
     */
    public function ensureTable(ConnectionInterface $connection): void;

    /**
     * Возвращает список уже применённых миграций (по id).
     *
     * @return list<string>
     */
    public function appliedIds(ConnectionInterface $connection, string $connectionName): array;

    /**
     * Помечает миграцию как применённую.
     */
    public function markApplied(ConnectionInterface $connection, string $id, string $connectionName): void;

    /**
     * Удаляет отметку о применении миграции.
     */
    public function removeApplied(ConnectionInterface $connection, string $id, string $connectionName): void;
}
