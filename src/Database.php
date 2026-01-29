<?php

declare(strict_types=1);

namespace PhpSoftBox\Database;

use PDO;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Configurator\DatabaseFactoryInterface;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Schema\SchemaManagerFactory;
use PhpSoftBox\Database\Schema\SchemaManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Точка входа (facade) для DBAL.
 *
 * Идея: можно использовать как без DI (через Database::fromConfig), так и через DI,
 * если контейнер умеет собирать DatabaseFactory/ConnectionManager.
 */
final readonly class Database
{
    private function __construct(
        private DatabaseFactoryInterface $factory,
        private ConnectionManagerInterface $manager,
    ) {
    }

    /**
     * Создаёт фасад из массива конфигурации.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, ?LoggerInterface $logger = null): self
    {
        $factory = new DatabaseFactory($config, $logger);

        $manager = new ConnectionManager($factory);

        return new self($factory, $manager);
    }

    /**
     * Возвращает фабрику подключений (низкоуровневый API).
     */
    public function factory(): DatabaseFactoryInterface
    {
        return $this->factory;
    }

    /**
     * Возвращает менеджер подключений (удобный API для DI).
     */
    public function manager(): ConnectionManagerInterface
    {
        return $this->manager;
    }

    /**
     * Возвращает подключение по имени.
     *
     * Поддерживается как плоский формат ("default"), так и read/write роли ("main.read", "main.write").
     */
    public function connection(string $name = 'default'): ConnectionInterface
    {
        return $this->manager->connection($name);
    }

    /**
     * Возвращает read-подключение для группы (например, main.read).
     */
    public function read(string $name = 'default'): ConnectionInterface
    {
        return $this->manager->read($name);
    }

    /**
     * Возвращает write-подключение для группы (например, main.write).
     */
    public function write(string $name = 'default'): ConnectionInterface
    {
        return $this->manager->write($name);
    }

    /**
     * Возвращает имя таблицы с префиксом по умолчанию (через default connection).
     */
    public function table(string $name, string $connection = 'default'): string
    {
        return $this->connection($connection)->table($name);
    }

    /**
     * Прокси к Connection::fetchAll() для подключения по умолчанию.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = [], string $connection = 'default'): array
    {
        return $this->connection($connection)->fetchAll($sql, $params);
    }

    /**
     * Прокси к Connection::fetchOne() для подключения по умолчанию.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = [], string $connection = 'default'): ?array
    {
        return $this->connection($connection)->fetchOne($sql, $params);
    }

    /**
     * Прокси к Connection::execute() для подключения по умолчанию.
     */
    public function execute(string $sql, array $params = [], string $connection = 'default'): int
    {
        return $this->connection($connection)->execute($sql, $params);
    }

    /**
     * Прокси к Connection::transaction() для подключения по умолчанию.
     *
     * @param IsolationLevelEnum|null $isolationLevel Уровень изоляции (применяется только для внешней транзакции).
     */
    public function transaction(
        callable $fn,
        string $connection = 'default',
        ?IsolationLevelEnum $isolationLevel = null,
    ): mixed {
        return $this->connection($connection)->transaction($fn, $isolationLevel);
    }

    /**
     * Возвращает сервис для чтения схемы (introspection) для указанного подключения.
     */
    public function schema(string $connection = 'default'): SchemaManagerInterface
    {
        $conn   = $this->connection($connection);
        $driver = (string) $conn->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return new SchemaManagerFactory()->create($conn, $driver);
    }
}
