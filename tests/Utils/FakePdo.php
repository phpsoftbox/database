<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

use PDO;

/**
 * Мини-PDO для тестов фабрики/компиляции.
 *
 * PDO невозможно просто так за-mock'ать в PHP без расширений,
 * поэтому используем наследника и переопределяем getAttribute().
 */
final class FakePdo extends PDO
{
    public function __construct(
        private readonly string $driverName,
    ) {
        // parent ctor не вызываем
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return $this->driverName;
        }

        return null;
    }
}
