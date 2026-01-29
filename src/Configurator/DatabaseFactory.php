<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Configurator;

use PDO;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Driver\DriverRegistry;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Dsn\DsnParser;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Pagination\Paginator as PaginationPaginator;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_replace;
use function explode;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * Минимальный конфигуратор для DBAL.
 *
 * Поддерживаемые драйверы (на текущем шаге): sqlite, mariadb, postgres.
 * Конфигурация задаётся массивом, чтобы было удобно использовать и с DI, и без.
 */
final readonly class DatabaseFactory implements DatabaseFactoryInterface
{
    private DriverRegistry $drivers;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private ?LoggerInterface $logger = null,
        private ?PaginationPaginator $paginator = null,
        ?DriverRegistry $drivers = null,
    ) {
        $this->drivers = $drivers ?? new DriverRegistry([
            new SqliteDriver(),
            new MariaDbDriver(),
            new PostgresDriver(),
        ]);
    }

    public function create(string $connection = 'default'): ConnectionInterface
    {
        $connections = $this->config['connections'] ?? null;
        if (!is_array($connections)) {
            throw new ConfigurationException('DatabaseFactory config must contain "connections" array.');
        }

        if (!array_key_exists('default', $connections)) {
            throw new ConfigurationException('DatabaseFactory config must contain "default" connection.');
        }

        if (array_key_exists('default', $connections) && !is_string($connections['default'])) {
            throw new ConfigurationException('Connections "default" must be a connection name (string).');
        }

        $connConfig = $this->resolveConnectionConfig($connections, $connection);

        $dsnString = $connConfig['dsn'] ?? null;
        if (!is_string($dsnString) || $dsnString === '') {
            throw new ConfigurationException('Connection config must contain non-empty "dsn".');
        }

        $prefix   = is_string($connConfig['prefix'] ?? null) ? (string) $connConfig['prefix'] : '';
        $readOnly = (bool) ($connConfig['readonly'] ?? false);

        $pdoOptions = $connConfig['options'] ?? [];
        if (!is_array($pdoOptions)) {
            throw new ConfigurationException('Connection option "options" must be an array.');
        }

        $dsn = new DsnParser()->parse($dsnString);

        // Достаём подходящий драйвер и даём ему провалидировать DSN
        $driver = $this->drivers->get($dsn->driver);
        $pdoDsn = $driver->pdoDsn($dsn);

        // 1) дефолты драйвера
        // 2) поверх — options из конфига соединения
        $pdoOptions = array_replace($driver->defaultPdoOptions(), $pdoOptions);

        $pdoUser     = $dsn->user;
        $pdoPassword = $dsn->password;

        $pdo = new PDO($pdoDsn, $pdoUser, $pdoPassword, $pdoOptions);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return new Connection(
            pdo: $pdo,
            driver: $driver,
            prefix: $prefix,
            readOnly: $readOnly,
            logger: $this->logger,
            paginator: $this->paginator,
        );
    }

    /**
     * @param array<string, mixed> $connections
     * @return array<string, mixed>
     */
    private function resolveConnectionConfig(array $connections, string $connection): array
    {
        if ($connection === 'default' && isset($connections['default'])) {
            $connection = $connections['default'];
        }

        $connConfig = $connections[$connection] ?? null;
        if (is_array($connConfig)) {
            if (array_key_exists('dsn', $connConfig)) {
                return $connConfig;
            }

            if (array_key_exists('write', $connConfig) || array_key_exists('read', $connConfig)) {
                $role       = array_key_exists('write', $connConfig) ? 'write' : 'read';
                $roleConfig = $connConfig[$role] ?? null;
                if (!is_array($roleConfig)) {
                    throw new ConfigurationException(sprintf('Unknown connection "%s".', $connection));
                }

                return $roleConfig;
            }
        }

        // Новый формат: connections['main']['read'] = [...] и запрос через "main.read"
        if (str_contains($connection, '.')) {
            [$group, $role] = explode('.', $connection, 2);
            if ($group === 'default' && isset($connections['default']) && is_string($connections['default'])) {
                $group = $connections['default'];
            }
            $groupConfig = $connections[$group] ?? null;
            if (!is_array($groupConfig)) {
                throw new ConfigurationException(sprintf('Unknown connection group "%s".', $group));
            }

            $roleConfig = $groupConfig[$role] ?? null;
            if (!is_array($roleConfig)) {
                throw new ConfigurationException(sprintf('Unknown connection "%s".', $connection));
            }

            return $roleConfig;
        }

        throw new ConfigurationException(sprintf('Unknown connection "%s".', $connection));
    }
}
