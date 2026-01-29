<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Driver\DriverRegistry;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DriverRegistry::class)]
final class DriverRegistryTest extends TestCase
{
    /**
     * Проверяет, что реестр позволяет зарегистрировать драйвер и затем получить его по имени.
     */
    #[Test]
    public function registersAndReturnsDriver(): void
    {
        $registry = new DriverRegistry();

        $registry->register(new SqliteDriver());

        self::assertTrue($registry->has('sqlite'));
        self::assertInstanceOf(SqliteDriver::class, $registry->get('sqlite'));
    }

    /**
     * Проверяет, что запрос неизвестного драйвера приводит к ConfigurationException.
     */
    #[Test]
    public function throwsForUnknownDriver(): void
    {
        $registry = new DriverRegistry();

        $this->expectException(ConfigurationException::class);
        $registry->get('unknown');
    }
}
