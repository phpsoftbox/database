<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

/**
 * Снимает и восстанавливает схему базы для конкретного драйвера.
 */
interface SchemaDumperInterface
{
    /**
     * Возвращает таблицы с префиксом подключения.
     *
     * @return list<string>
     */
    public function tables(ConnectionInterface $connection): array;

    /**
     * Возвращает инструкции, создающие таблицы и строки таблиц с данными.
     *
     * @param list<string> $tables Полные имена таблиц.
     * @param list<string> $dataTables Полные имена таблиц, строки которых нужно сохранить.
     * @return list<string>
     */
    public function dump(ConnectionInterface $connection, array $tables, array $dataTables): array;

    /**
     * Выполняет инструкции снимка в пустой базе.
     *
     * @param list<string> $statements
     */
    public function load(ConnectionInterface $connection, array $statements): void;
}
