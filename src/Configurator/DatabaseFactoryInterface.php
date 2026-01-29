<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Configurator;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

interface DatabaseFactoryInterface
{
    /**
     * Создаёт подключение по имени (канал/алиас подключения).
     */
    public function create(string $connection = 'default'): ConnectionInterface;
}
