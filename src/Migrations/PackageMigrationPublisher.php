<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function basename;
use function copy;
use function count;
use function explode;
use function file_exists;
use function glob;
use function is_dir;
use function mkdir;
use function rtrim;
use function sprintf;
use function strlen;
use function substr;

use const GLOB_ONLYDIR;

final class PackageMigrationPublisher
{
    /**
     * @return list<array{package: string, source: string, target: string, status: string}>
     */
    public function publish(string $vendorPath, string $targetPath, ?string $package = null, bool $force = false): array
    {
        $vendorPath = rtrim($vendorPath, '/');
        $targetPath = rtrim($targetPath, '/');
        if (!is_dir($vendorPath)) {
            throw new ConfigurationException('Vendor directory does not exist: ' . $vendorPath);
        }

        if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
            throw new ConfigurationException('Cannot create migrations directory: ' . $targetPath);
        }

        $published = [];
        foreach ($this->packageMigrationDirectories($vendorPath, $package) as $packageName => $directory) {
            $files = glob($directory . '/*.php');
            if ($files === false) {
                continue;
            }

            foreach ($files as $source) {
                $target = $targetPath . '/' . basename($source);
                if (file_exists($target) && !$force) {
                    $published[] = [
                        'package' => $packageName,
                        'source'  => $source,
                        'target'  => $target,
                        'status'  => 'skipped',
                    ];
                    continue;
                }

                if (!copy($source, $target)) {
                    throw new ConfigurationException(sprintf('Cannot copy migration "%s" to "%s".', $source, $target));
                }

                $published[] = [
                    'package' => $packageName,
                    'source'  => $source,
                    'target'  => $target,
                    'status'  => 'published',
                ];
            }
        }

        return $published;
    }

    /**
     * @return array<string, string>
     */
    private function packageMigrationDirectories(string $vendorPath, ?string $package): array
    {
        if ($package !== null && $package !== '') {
            $directory = $vendorPath . '/' . $package . '/database/migrations';

            return is_dir($directory) ? [$package => $directory] : [];
        }

        $directories = [];
        foreach (glob($vendorPath . '/*/*/database/migrations', GLOB_ONLYDIR) ?: [] as $directory) {
            $relative = substr($directory, strlen($vendorPath) + 1);
            $parts    = explode('/', $relative);
            if (count($parts) < 4) {
                continue;
            }

            $directories[$parts[0] . '/' . $parts[1]] = $directory;
        }

        return $directories;
    }
}
