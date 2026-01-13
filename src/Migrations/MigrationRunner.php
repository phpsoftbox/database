<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;

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

        $applied = array_flip($this->repository->appliedIds($conn));

        $appliedNow = [];
        foreach ($plan->all() as $item) {
            $id = $item['id'];
            $migration = $item['migration'];

            if (isset($applied[$id])) {
                continue;
            }

            $conn->transaction(function (ConnectionInterface $tx) use ($migration, $id): void {
                if ($migration instanceof AbstractMigration) {
                    $migration->setContext(new MigrationContext($tx));
                }

                $migration->up();
                $this->repository->markApplied($tx, $id);
            });

            $appliedNow[] = $id;
            $applied[$id] = true;
        }

        return $appliedNow;
    }

    private function writeConnection(): ConnectionInterface
    {
        // Если передали имя группы (main), используем main.write.
        // Если передали плоское имя (default), тоже корректно.
        return $this->connections->write($this->connectionName);
    }
}
