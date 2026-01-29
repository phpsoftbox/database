<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Database;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function class_exists;

#[CoversClass(Database::class)]
final class DatabaseTest extends TestCase
{
    /**
     * Проверяет, что класс точки входа компонента доступен.
     */
    #[Test]
    public function componentLoads(): void
    {
        self::assertTrue(class_exists(Database::class));
    }
}
