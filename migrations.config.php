<?php

declare(strict_types=1);

/**
 * Пример конфигурации для packages/Database/bin/migrate.php.
 *
 * В реальном приложении этот конфиг будет частью приложения,
 * а не пакета (и скорее всего будет собираться из env).
 */
return [
    'connections' => [
        'default' => [
            'dsn' => 'sqlite:///:memory:',
            'readonly' => false,
        ],

        // Пример группы read/write:
        // 'main' => [
        //     'read' => ['dsn' => 'postgres://user:pass@ro-host:5432/app', 'readonly' => true],
        //     'write' => ['dsn' => 'postgres://user:pass@rw-host:5432/app', 'readonly' => false],
        // ],
    ],
];

