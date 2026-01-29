<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_flip;
use function array_reverse;
use function count;

/**
 * Запускает миграции.
 *
 * Принципы:
 * - DDL/DML миграций всегда выполняем через write-подключение.
 * - Репозиторий хранит факт применения (id).
 * - Повторный запуск не применяет миграции повторно.
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
     * @return list<string> Идентификаторы применённых миграций.
     */
    public function migrate(MigrationPlan $plan): array
    {
        $conn = $this->writeConnection();
        $this->repository->ensureTable($conn);

        $applied = array_flip($this->repository->appliedIds($conn, $this->connectionName));

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

    private function writeConnection(): ConnectionInterface
    {
        // Если передали имя группы (main), используем main.write.
        // Если передали плоское имя (default), тоже корректно.
        return $this->connections->write($this->connectionName);
    }
}
