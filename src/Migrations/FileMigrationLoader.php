<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use FilesystemIterator;
use PhpSoftBox\Database\Exception\ConfigurationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function basename;
use function glob;
use function is_dir;
use function preg_match;
use function rtrim;
use function sprintf;
use function strcmp;
use function usort;

/**
 * Загружает миграции из директории.
 *
 * Это "примерный" раннер/лоадер для будущего CLI приложения без привязки к symfony/console.
 */
final class FileMigrationLoader
{
    /**
     * @return list<array{id: string, migration: MigrationInterface}>
     */
    public function load(string $directory, bool $recursive = false): array
    {
        if (!is_dir($directory)) {
            throw new ConfigurationException(sprintf('Migrations directory "%s" does not exist.', $directory));
        }

        $files = $this->collectFiles($directory, $recursive);

        return $this->loadFiles($files);
    }

    /**
     * @param list<string> $files
     * @return list<array{id: string, migration: MigrationInterface}>
     */
    public function loadFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        // Порядок определяется именем миграции (YYYYMMDDHHMMSS_description), а не путём:
        // при рекурсивной загрузке подкаталоги не должны менять хронологию.
        usort($files, static fn (string $a, string $b): int => strcmp(basename($a), basename($b)) ?: strcmp($a, $b));

        $out = [];
        foreach ($files as $file) {
            $base = basename($file, '.php');
            $id   = $this->idFromFilename($base);

            $migration = require $file;
            if (!$migration instanceof MigrationInterface) {
                throw new ConfigurationException(sprintf('Migration file "%s" must return MigrationInterface instance.', $file));
            }

            $out[] = ['id' => $id, 'migration' => $migration];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectFiles(string $directory, bool $recursive): array
    {
        $directory = rtrim($directory, '/');

        if (!$recursive) {
            $files = glob($directory . '/*.php');
            if ($files === false) {
                return [];
            }

            return $files;
        }

        $files    = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }

        return $files;
    }

    private function idFromFilename(string $baseName): string
    {
        // YYYYMMDDHHMMSS_description
        if (!preg_match('/^(\d{14})_[a-z0-9][a-z0-9_]*$/', $baseName)) {
            throw new ConfigurationException(sprintf(
                'Invalid migration filename "%s". Expected format: YYYYMMDDHHMMSS_description.php',
                $baseName . '.php',
            ));
        }

        return $baseName;
    }
}
