<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Database\Migrations\MigrationsConfig;
use PhpSoftBox\Database\Migrations\PackageMigrationPublisher;
use Throwable;

use function count;
use function getcwd;
use function is_string;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function trim;

final class PublishMigrationsHandler implements HandlerInterface
{
    public function __construct(
        private readonly MigrationsConfig $config,
        private readonly PackageMigrationPublisher $publisher = new PackageMigrationPublisher(),
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
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

        $targetPath = $this->targetPath($basePaths[0] ?? null, $runner->request()->option('path'));
        if ($targetPath === null) {
            $runner->io()->writeln('Некорректный путь публикации миграций.', 'error');

            return Response::FAILURE;
        }

        $package = $runner->request()->option('package');
        if ($package !== null && (!is_string($package) || trim($package) === '')) {
            $runner->io()->writeln('Некорректное имя пакета.', 'error');

            return Response::FAILURE;
        }

        $vendorPath = $this->vendorPath($runner->request()->option('vendor'));
        if ($vendorPath === null) {
            $runner->io()->writeln('Некорректный путь к vendor.', 'error');

            return Response::FAILURE;
        }

        try {
            $published = $this->publisher->publish(
                vendorPath: $vendorPath,
                targetPath: $targetPath,
                package: is_string($package) ? trim($package) : null,
                force: (bool) $runner->request()->option('force'),
            );
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка публикации миграций: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $publishedCount = 0;
        foreach ($published as $item) {
            if ($item['status'] === 'published') {
                $publishedCount++;
            }
            $runner->io()->writeln(sprintf('[%s] %s -> %s', $item['status'], $item['package'], $item['target']));
        }

        $runner->io()->writeln('Опубликовано миграций: ' . $publishedCount . ' из ' . count($published), 'success');

        return Response::SUCCESS;
    }

    private function targetPath(mixed $basePath, mixed $relative): ?string
    {
        if (!is_string($basePath) || $basePath === '') {
            return null;
        }

        if ($relative !== null && (!is_string($relative) || $relative === '' || str_starts_with($relative, '/'))) {
            return null;
        }

        $target = rtrim($basePath, '/');
        if (is_string($relative) && $relative !== '') {
            $target .= '/' . trim($relative, '/');
        }

        return $this->absolutePath($target);
    }

    private function vendorPath(mixed $value): ?string
    {
        if ($value !== null && (!is_string($value) || trim($value) === '')) {
            return null;
        }

        return $this->absolutePath(is_string($value) ? trim($value) : 'vendor');
    }

    private function absolutePath(string $path): ?string
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        return rtrim($cwd, '/') . '/' . $path;
    }
}
