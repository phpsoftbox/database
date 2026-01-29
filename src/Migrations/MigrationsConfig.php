<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_key_first;
use function is_string;
use function rtrim;

final class MigrationsConfig
{
    private string $basePath;
    private string $defaultConnection;
    /** @var array<string, string> */
    private array $paths = [];

    /**
     * @param array<string, string> $paths
     */
    public function __construct(string $basePath, array $paths = [], ?string $defaultConnection = null)
    {
        $basePath = rtrim($basePath, '/');
        if ($basePath === '') {
            throw new ConfigurationException('Migrations base path is required.');
        }

        $this->basePath = $basePath;

        foreach ($paths as $connection => $path) {
            if (!is_string($connection) || $connection === '') {
                continue;
            }
            if (!is_string($path) || $path === '') {
                throw new ConfigurationException('Migration path for connection "' . $connection . '" must be string.');
            }

            $this->paths[$connection] = rtrim($path, '/');
        }

        if ($defaultConnection === null || $defaultConnection === '') {
            $defaultConnection = $this->paths !== [] ? (string) array_key_first($this->paths) : 'default';
        }

        $this->defaultConnection = $defaultConnection;
    }

    public function defaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function hasConnection(string $name): bool
    {
        return $name !== '';
    }

    /**
     * @return list<string>
     */
    public function paths(string $connection): array
    {
        if ($connection === '') {
            throw new ConfigurationException('Migration connection name is required.');
        }

        $path = $this->paths[$connection] ?? ($this->basePath . '/' . $connection);

        return [$path];
    }
}
