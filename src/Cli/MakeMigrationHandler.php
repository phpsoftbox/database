<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Database\Migrations\MigrationCreator;
use PhpSoftBox\Database\Migrations\MigrationsConfig;
use Throwable;

use function getcwd;
use function is_dir;
use function is_string;
use function mkdir;
use function rtrim;
use function str_starts_with;

final class MakeMigrationHandler implements HandlerInterface
{
    public function __construct(
        private readonly MigrationsConfig $config,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $name = $runner->request()->param('name');
        if (!is_string($name) || $name === '') {
            $runner->io()->writeln('Имя миграции не задано.', 'error');

            return Response::FAILURE;
        }

        $connectionName = $runner->request()->option('connection');
        if (!is_string($connectionName) || $connectionName === '') {
            $connectionName = $this->config->defaultConnection();
        }

        try {
            $basePaths = $this->config->paths($connectionName);
        } catch (Throwable $exception) {
            $runner->io()->writeln($exception->getMessage(), 'error');

            return Response::FAILURE;
        }
        $basePath = $basePaths[0] ?? null;
        if ($basePath === null) {
            $runner->io()->writeln('Пути миграций не сконфигурированы.', 'error');

            return Response::FAILURE;
        }

        $relative = $runner->request()->option('path');
        if ($relative !== null && (!is_string($relative) || $relative === '')) {
            $runner->io()->writeln('Некорректный путь к миграциям.', 'error');

            return Response::FAILURE;
        }

        if (is_string($relative) && str_starts_with($relative, '/')) {
            $runner->io()->writeln('Путь должен быть относительным.', 'error');

            return Response::FAILURE;
        }

        $target = $basePath;
        if (is_string($relative) && $relative !== '') {
            $target = rtrim($basePath, '/') . '/' . $relative;
        }

        $path = $this->normalizePath($target);
        if ($path === null) {
            $runner->io()->writeln('Некорректный путь к миграциям.', 'error');

            return Response::FAILURE;
        }

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            $runner->io()->writeln('Не удалось создать директорию миграций: ' . $path, 'error');

            return Response::FAILURE;
        }

        $creator = new MigrationCreator();

        try {
            $file = $creator->create($path, $name);
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка создания миграции: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $runner->io()->writeln('Создан файл миграции: ' . $file, 'success');

        return Response::SUCCESS;
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
