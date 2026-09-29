<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Configurator;

use PDO;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Driver\DriverRegistry;
use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\MySqlDriver;
use PhpSoftBox\Database\Driver\PostgresDriver;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\Dsn\DsnParser;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\Profiler\DatabaseProfilerCollector;
use PhpSoftBox\Database\Warmup\WarmupStore;
use PhpSoftBox\Pagination\Paginator as PaginationPaginator;
use PhpSoftBox\Profiler\ProfilerInterface;
use Psr\Log\LoggerInterface;

use function array_key_exists;
use function array_replace;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * Минимальный конфигуратор для DBAL.
 *
 * Поддерживаемые драйверы: sqlite, mysql, mariadb, postgres.
 * Конфигурация задаётся массивом, чтобы было удобно использовать и с DI, и без.
 */
final class DatabaseFactory implements DatabaseFactoryInterface
{
    private readonly DriverRegistry $drivers;

    /**
     * Warmup store на группу подключений (main, main.read, main.write и default → main используют один store),
     * если общий store не передан в конструктор.
     *
     * @var array<string, WarmupStore>
     */
    private array $groupWarmupStores = [];

    /**
     * @param array<string, mixed> $config
     * @param WarmupStore|null $warmupStore Общий store для всех подключений фабрики. Если не задан,
     *                                      store создаётся на группу подключений (лимит — config['warmup']['max_entries']).
     */
    public function __construct(
        private readonly array $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?PaginationPaginator $paginator = null,
        ?DriverRegistry $drivers = null,
        private readonly ?WarmupStore $warmupStore = null,
        private readonly ?ProfilerInterface $profiler = null,
        private readonly ?DatabaseProfilerCollector $profilerCollector = null,
    ) {
        $this->drivers = $drivers ?? new DriverRegistry([
            new SqliteDriver(),
            new MariaDbDriver(),
            new MySqlDriver(),
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
            warmupConnectionName: $connection,
            warmupStore: $this->warmupStoreFor($connections, $connection),
            profiler: $this->profiler,
            profilerCollector: $this->profilerCollector,
        );
    }

    /**
     * Очищает warmup store всех подключений, созданных фабрикой (hook сброса состояния между запросами воркера).
     */
    public function clearWarmup(): void
    {
        $this->warmupStore?->clear();

        foreach ($this->groupWarmupStores as $store) {
            $store->clear();
        }
    }

    /**
     * @param array<string, mixed> $connections
     */
    private function warmupStoreFor(array $connections, string $connection): WarmupStore
    {
        if ($this->warmupStore !== null) {
            return $this->warmupStore;
        }

        $group = $this->connectionGroup($connections, $connection);

        return $this->groupWarmupStores[$group] ??= new WarmupStore($this->warmupMaxEntries());
    }

    /**
     * Имя группы подключения: "default" → имя по умолчанию, "main.read"/"main.write" → "main".
     *
     * @param array<string, mixed> $connections
     */
    private function connectionGroup(array $connections, string $connection): string
    {
        $default = is_string($connections['default'] ?? null) ? $connections['default'] : 'default';

        $group = str_contains($connection, '.') ? explode('.', $connection, 2)[0] : $connection;

        return $group === 'default' ? $default : $group;
    }

    private function warmupMaxEntries(): int
    {
        $warmup = $this->config['warmup'] ?? [];
        $max    = is_array($warmup) ? ($warmup['max_entries'] ?? null) : null;

        if ($max === null) {
            return WarmupStore::DEFAULT_MAX_ENTRIES;
        }

        if (!is_int($max) || $max < 1) {
            throw new ConfigurationException('Config "warmup.max_entries" must be a positive integer.');
        }

        return $max;
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
