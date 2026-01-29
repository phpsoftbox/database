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
use PhpSoftBox\Database\Migrations\SqlMigrationRepository;
use Throwable;

use function count;
use function getcwd;
use function is_dir;
use function is_string;
use function rtrim;
use function str_starts_with;

final class MigrateHandler implements HandlerInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly MigrationsConfig $config,
        private readonly ?MigrationRepositoryInterface $repository = null,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $connectionName = $this->resolveConnection($runner);
        if ($connectionName === null) {
            $runner->io()->writeln('Некорректное имя подключения.', 'error');

            return Response::FAILURE;
        }

        try {
            $paths = $this->resolvePaths($runner, $connectionName);
        } catch (Throwable $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::FAILURE;
        }
        if ($paths === []) {
            $runner->io()->writeln('Не найдены директории миграций.', 'error');

            return Response::FAILURE;
        }

        $loader = new FileMigrationLoader();
        $plan   = new MigrationPlan();
        $known  = [];
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $runner->io()->writeln('Директория миграций не найдена: ' . $path, 'error');

                return Response::FAILURE;
            }

            foreach ($loader->load($path, recursive: false) as $item) {
                if (isset($known[$item['id']])) {
                    $runner->io()->writeln('Дублирующаяся миграция: ' . $item['id'], 'error');

                    return Response::FAILURE;
                }
                $known[$item['id']] = true;
                $plan->add($item['id'], $item['migration']);
            }
        }

        $runnerService = new MigrationRunner(
            connections: $this->connections,
            repository: $this->repository ?? new SqlMigrationRepository(),
            connectionName: $connectionName,
        );

        try {
            $applied = $runnerService->migrate($plan);
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка миграции: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $runner->io()->writeln('Применено миграций: ' . count($applied), 'success');
        foreach ($applied as $id) {
            $runner->io()->writeln(' - ' . $id, 'info');
        }

        return Response::SUCCESS;
    }

    private function resolveConnection(RunnerInterface $runner): ?string
    {
        $connectionName = $runner->request()->option('connection');
        if (is_string($connectionName) && $connectionName !== '') {
            return $connectionName;
        }

        return $this->config->defaultConnection();
    }

    /**
     * @return list<string>
     */
    private function resolvePaths(RunnerInterface $runner, string $connectionName): array
    {
        $basePaths = $this->config->paths($connectionName);

        $relative = $runner->request()->option('path');
        if ($relative !== null && (!is_string($relative) || $relative === '')) {
            return [];
        }

        $paths = [];
        foreach ($basePaths as $basePath) {
            $path = $basePath;
            if (is_string($relative) && $relative !== '') {
                if (str_starts_with($relative, '/')) {
                    return [];
                }
                $path = rtrim($basePath, '/') . '/' . $relative;
            }

            $resolved = $this->normalizePath($path);
            if ($resolved !== null) {
                $paths[] = $resolved;
            }
        }

        return $paths;
    }

    private function normalizePath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }

        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd !== false) {
                $path = rtrim($cwd, '/') . '/' . $path;
            }
        }

        return $path;
    }
}
