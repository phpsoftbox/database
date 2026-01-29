<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

use function is_string;

use const DATE_ATOM;

/**
 * SQL-репозиторий применённых миграций.
 *
 * Хранит имя миграции и время применения.
 */
final class SqlMigrationRepository implements MigrationRepositoryInterface
{
    public function __construct(
        private readonly string $table = 'migrations',
    ) {
    }

    public function ensureTable(ConnectionInterface $connection): void
    {
        // Создаём через SchemaBuilder, чтобы:
        // - SQL был корректным для текущего драйвера
        // - учитывался table prefix из конфигурации
        $connection->schema()->createIfNotExists($this->table, function (TableBlueprint $table): void {
            // Здесь нам важна переносимость. Поэтому используем id() как первичный ключ.
            // (Для postgres это SERIAL, для mysql AUTOINCREMENT, для sqlite INTEGER PRIMARY KEY).
            $table->id();

            // Строковый идентификатор миграции.
            $table->string('name');

            // Имя подключения, для которого применена миграция.
            $table->string('connection_name');

            $table->unique(['connection_name', 'name'], 'migrations_connection_name_unique');

            // Время применения (UTC).
            $table->datetime('applied_datetime');
        });
    }

    public function appliedIds(ConnectionInterface $connection, string $connectionName): array
    {
        $this->ensureTable($connection);

        $rows = $connection->fetchAll("
            SELECT name
            FROM {$connection->table($this->table)}
            WHERE connection_name = :connection_name
            ORDER BY id
        ", ['connection_name' => $connectionName]);

        $out = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function markApplied(ConnectionInterface $connection, string $id, string $connectionName): void
    {
        $this->ensureTable($connection);

        // Генерируем последовательный id вручную, чтобы схема оставалась переносимой.
        $nextIdRow = $connection->fetchOne("
            SELECT COALESCE(MAX(id), 0) + 1 AS next_id
            FROM {$connection->table($this->table)}
        ");

        $nextId = (int) ($nextIdRow['next_id'] ?? 1);

        $connection->execute("
            INSERT INTO {$connection->table($this->table)}
                (id, name, connection_name, applied_datetime)
            VALUES
                (:id, :name, :connection_name, :applied_datetime)
        ", [
            'id'               => $nextId,
            'name'             => $id,
            'connection_name'  => $connectionName,
            'applied_datetime' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(DATE_ATOM),
        ]);
    }

    public function removeApplied(ConnectionInterface $connection, string $id, string $connectionName): void
    {
        $this->ensureTable($connection);

        $connection->execute("
            DELETE FROM {$connection->table($this->table)}
            WHERE name = :name AND connection_name = :connection_name
        ", [
            'name'            => $id,
            'connection_name' => $connectionName,
        ]);
    }
}
