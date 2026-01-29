<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function sprintf;
use function strtolower;

/**
 * Реестр драйверов.
 *
 * Нужен, чтобы DatabaseFactory не был жёстко привязан к одному драйверу.
 */
final class DriverRegistry
{
    /**
     * @var array<string, DriverInterface>
     */
    private array $driversByName = [];

    /**
     * @param list<DriverInterface> $drivers
     */
    public function __construct(array $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(DriverInterface $driver): void
    {
        $name                       = strtolower($driver->name());
        $this->driversByName[$name] = $driver;
    }

    public function has(string $driverName): bool
    {
        return isset($this->driversByName[strtolower($driverName)]);
    }

    public function get(string $driverName): DriverInterface
    {
        $name   = strtolower($driverName);
        $driver = $this->driversByName[$name] ?? null;
        if ($driver === null) {
            throw new ConfigurationException(sprintf('Unsupported DB driver "%s".', $driverName));
        }

        return $driver;
    }
}
