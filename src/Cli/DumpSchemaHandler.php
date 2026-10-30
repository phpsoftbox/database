<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Migrations\FileMigrationLoader;
use PhpSoftBox\Database\Migrations\MigrationPlan;
use PhpSoftBox\Database\Migrations\MigrationRepositoryInterface;
use PhpSoftBox\Database\Migrations\MigrationRunner;
use PhpSoftBox\Database\Migrations\MigrationsConfig;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaSnapshotFile;
use PhpSoftBox\Database\Migrations\SqlMigrationRepository;
use PhpSoftBox\ErrorFormatter\ThrowableFormatter;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function getcwd;
use function is_dir;
use function is_string;
use function rtrim;
use function str_starts_with;
use function trim;

/**
 * Записывает снимок схемы полностью применённой базы в `schema.sql` каталога миграций.
 *
 * Таблицы с данными берутся из --data, иначе из прежнего снимка.
 */
final class DumpSchemaHandler implements HandlerInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly MigrationsConfig $config,
        private readonly ?MigrationRepositoryInterface $repository = null,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $connectionName = $runner->request()->option('connection');
        if (!is_string($connectionName) || $connectionName === '') {
            $connectionName = $this->config->defaultConnection();
        }

        $directory = $this->config->paths($connectionName)[0];
        if (!str_starts_with($directory, '/') && ($cwd = getcwd()) !== false) {
            $directory = rtrim($cwd, '/') . '/' . $directory;
        }
        if (!is_dir($directory)) {
            $runner->io()->writeln('Директория миграций не найдена: ' . $directory, 'error');

            return Response::FAILURE;
        }

        $path = $directory . '/' . SchemaSnapshotFile::FILE_NAME;
        $plan = new MigrationPlan();

        try {
            foreach (new FileMigrationLoader()->load($directory, recursive: false) as $item) {
                $plan->add($item['id'], $item['migration']);
            }

            $data       = $runner->request()->option('data');
            $dataTables = is_string($data)
                ? array_values(array_filter(array_map(trim(...), explode(',', $data)), static fn (string $table): bool => $table !== ''))
                : (SchemaSnapshotFile::exists($path) ? SchemaSnapshotFile::read($path)->dataTables : []);

            $snapshot = new MigrationRunner(
                connections: $this->connections,
                repository: $this->repository ?? new SqlMigrationRepository(),
                connectionName: $connectionName,
            )->dump($plan, $dataTables);

            SchemaSnapshotFile::write($path, $snapshot);
        } catch (Throwable $exception) {
            foreach (ThrowableFormatter::toLines($exception, 'Ошибка снимка схемы:') as $line) {
                $runner->io()->writeln($line, 'error');
            }

            return Response::FAILURE;
        }

        $runner->io()->writeln('Снимок схемы записан: ' . $path, 'success');
        $runner->io()->writeln(' - миграций: ' . count($snapshot->migrations) . ', инструкций: ' . count($snapshot->statements), 'info');

        return Response::SUCCESS;
    }
}
