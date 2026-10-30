<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaDumperFactory;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaDumperInterface;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaSnapshot;

use function array_column;
use function array_diff;
use function array_filter;
use function array_flip;
use function array_reverse;
use function array_values;
use function count;
use function implode;
use function in_array;
use function sprintf;

/**
 * Запускает миграции.
 *
 * Принципы:
 * - DDL/DML миграций всегда выполняем через write-подключение.
 * - Репозиторий хранит факт применения (id).
 * - Повторный запуск не применяет миграции повторно.
 * - Пустая база разворачивается из снимка схемы, если он передан; покрытые снимком
 *   миграции отмечаются применёнными, а более новые применяются как обычно.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly MigrationRepositoryInterface $repository = new SqlMigrationRepository(),
        private readonly string $connectionName = 'default',
    ) {
    }

    /**
     * Применяет миграции.
     *
     * Миграции, покрытые снимком и загруженные из него, в результат не входят.
     *
     * @return list<string> Идентификаторы применённых миграций.
     */
    public function migrate(MigrationPlan $plan, ?SchemaSnapshot $snapshot = null): array
    {
        $conn = $this->writeConnection();
        $this->repository->ensureTable($conn);

        $appliedIds = $this->repository->appliedIds($conn, $this->connectionName);
        if ($snapshot !== null) {
            $appliedIds = $this->applySnapshot($conn, $plan, $snapshot, $appliedIds);
        }

        $applied = array_flip($appliedIds);

        $appliedNow = [];
        foreach ($plan->all() as $item) {
            $id        = $item['id'];
            $migration = $item['migration'];

            if (isset($applied[$id])) {
                continue;
            }

            $conn->transaction(function (ConnectionInterface $tx) use ($migration, $id): void {
                if ($migration instanceof AbstractMigration) {
                    $migration->setContext(new MigrationContext($tx));
                }

                $migration->up();
                $this->repository->markApplied($tx, $id, $this->connectionName);
            });

            $appliedNow[] = $id;
            $applied[$id] = true;
        }

        return $appliedNow;
    }

    /**
     * Откатывает последние миграции.
     *
     * @return list<string> Идентификаторы откатанных миграций.
     */
    public function rollback(MigrationPlan $plan, int $steps = 1): array
    {
        if ($steps <= 0) {
            return [];
        }

        $conn = $this->writeConnection();
        $this->repository->ensureTable($conn);

        $planMap = [];
        foreach ($plan->all() as $item) {
            $planMap[$item['id']] = $item['migration'];
        }

        $applied = $this->repository->appliedIds($conn, $this->connectionName);
        if ($applied === []) {
            return [];
        }

        $rolledBack = [];
        $applied    = array_reverse($applied);

        foreach ($applied as $id) {
            if (count($rolledBack) >= $steps) {
                break;
            }

            if (!isset($planMap[$id])) {
                throw new ConfigurationException(
                    'Migration not found in plan: ' . $id,
                );
            }

            $migration = $planMap[$id];

            $conn->transaction(function (ConnectionInterface $tx) use ($migration, $id): void {
                if ($migration instanceof AbstractMigration) {
                    $migration->setContext(new MigrationContext($tx));
                }

                $migration->down();
                $this->repository->removeApplied($tx, $id, $this->connectionName);
            });

            $rolledBack[] = $id;
        }

        return $rolledBack;
    }

    /**
     * Снимает схему базы, в которой применены все миграции плана.
     *
     * @param list<string> $dataTables Таблицы без префикса, строки которых нужно сохранить.
     */
    public function dump(MigrationPlan $plan, array $dataTables = []): SchemaSnapshot
    {
        $conn = $this->writeConnection();
        $this->repository->ensureTable($conn);

        $applied = $this->repository->appliedIds($conn, $this->connectionName);
        $pending = array_values(array_diff(array_column($plan->all(), 'id'), $applied));
        if ($pending !== []) {
            throw new ConfigurationException('Перед снимком схемы примените миграции: ' . implode(', ', $pending));
        }

        $dumper = SchemaDumperFactory::create($conn);
        $tables = $this->userTables($conn, $dumper);
        $data   = [];
        foreach ($dataTables as $table) {
            if (!in_array($conn->table($table), $tables, true)) {
                throw new ConfigurationException('Таблица для данных снимка не найдена: ' . $table);
            }
            $data[] = $conn->table($table);
        }

        return new SchemaSnapshot(
            driver: $conn->driver()->name(),
            prefix: $conn->prefix(),
            migrations: $applied,
            dataTables: $dataTables,
            statements: $dumper->dump($conn, $tables, $data),
        );
    }

    /**
     * Загружает снимок в пустую базу или проверяет, что база не отстала от снимка.
     *
     * @param list<string> $appliedIds
     * @return list<string> Применённые миграции с учётом снимка.
     */
    private function applySnapshot(ConnectionInterface $conn, MigrationPlan $plan, SchemaSnapshot $snapshot, array $appliedIds): array
    {
        if ($snapshot->driver !== $conn->driver()->name() || $snapshot->prefix !== $conn->prefix()) {
            throw new ConfigurationException(sprintf(
                'Снимок схемы создан для %s с префиксом "%s", а подключение %s — %s с префиксом "%s".',
                $snapshot->driver,
                $snapshot->prefix,
                $this->connectionName,
                $conn->driver()->name(),
                $conn->prefix(),
            ));
        }

        $dumper = SchemaDumperFactory::create($conn);
        if ($appliedIds === [] && $this->userTables($conn, $dumper) === []) {
            $conn->transaction(function (ConnectionInterface $tx) use ($dumper, $snapshot): void {
                $dumper->load($tx, $snapshot->statements);
                foreach ($snapshot->migrations as $id) {
                    $this->repository->markApplied($tx, $id, $this->connectionName);
                }
            });

            return $snapshot->migrations;
        }

        // Миграции, удалённые после снимка, база должна была получить раньше.
        $known   = array_flip([...$appliedIds, ...array_column($plan->all(), 'id')]);
        $missing = array_values(array_filter($snapshot->migrations, static fn (string $id): bool => !isset($known[$id])));
        if ($missing !== []) {
            throw new ConfigurationException(sprintf(
                'База подключения %s не содержит миграции, которые свёрнуты в снимок схемы и удалены: %s. Сначала обновите её версией, где эти миграции ещё есть.',
                $this->connectionName,
                implode(', ', $missing),
            ));
        }

        return $appliedIds;
    }

    /**
     * @return list<string>
     */
    private function userTables(ConnectionInterface $conn, SchemaDumperInterface $dumper): array
    {
        $migrations = $this->repository instanceof SqlMigrationRepository ? $conn->table($this->repository->table()) : null;

        return array_values(array_filter($dumper->tables($conn), static fn (string $table): bool => $table !== $migrations));
    }

    private function writeConnection(): ConnectionInterface
    {
        // Если передали имя группы (main), используем main.write.
        // Если передали плоское имя (default), тоже корректно.
        return $this->connections->write($this->connectionName);
    }
}
