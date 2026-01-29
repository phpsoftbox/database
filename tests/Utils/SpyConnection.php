<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

use PDO;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Driver\SqliteDriver;
use PhpSoftBox\Database\IsolationLevelEnum;
use PhpSoftBox\Database\QueryBuilder\QueryFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderFactory;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;
use Psr\Log\LoggerInterface;

use function count;
use function explode;
use function implode;

/**
 * Упрощённый spy для ConnectionInterface.
 *
 * Нужен, чтобы проверить, какие SQL выражения выполняет SchemaBuilder,
 * не поднимая реальные БД.
 */
class SpyConnection implements ConnectionInterface
{
    /**
     * @var list<array{sql: string, params: array}>
     */
    public array $executed = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = '',
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $readOnly = false,
        ?DriverInterface $driver = null,
    ) {
        $this->driver = $driver ?? new SqliteDriver();
    }

    private readonly DriverInterface $driver;

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        return [];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        return null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        return 1;
    }

    public function transaction(callable $fn, ?IsolationLevelEnum $isolationLevel = null): mixed
    {
        return $fn($this);
    }

    public function lastInsertId(?string $name = null): string
    {
        return $this->pdo->lastInsertId($name);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function table(string $name): string
    {
        if ($name === '' || $this->prefix === '') {
            return $name;
        }

        $parts             = explode('.', $name);
        $lastIndex         = count($parts) - 1;
        $parts[$lastIndex] = $this->prefix . $parts[$lastIndex];

        return implode('.', $parts);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->driver->createQuoter()->ident($identifier);
    }

    public function quoteTable(string $table): string
    {
        return $this->driver->createQuoter()->dotted($this->table($table));
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function schema(): SchemaBuilderInterface
    {
        return new SchemaBuilderFactory()->create($this);
    }

    public function query(): QueryFactory
    {
        return new QueryFactory($this);
    }

    public function driver(): DriverInterface
    {
        return $this->driver;
    }
}
