<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Connection;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

/**
 * Менеджер подключений.
 *
 * Нужен для удобной работы с несколькими подключениями (например, default, read, write)
 * и для DI-сценариев, когда фабрика доступна как сервис.
 */
interface ConnectionManagerInterface
{
    /**
     * Возвращает подключение по имени (алиас подключения из конфигурации).
     */
    public function connection(string $name = 'default'): ConnectionInterface;

    /**
     * Возвращает read-подключение для набора (например, main.read).
     */
    public function read(string $name = 'default'): ConnectionInterface;

    /**
     * Возвращает write-подключение для набора (например, main.write).
     */
    public function write(string $name = 'default'): ConnectionInterface;
}
