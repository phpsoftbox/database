<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Exception\ConfigurationException;

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
    public function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new ConfigurationException(sprintf('Migrations directory "%s" does not exist.', $directory));
        }

        $files = glob(rtrim($directory, '/'). '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $file) {
            $base = basename($file, '.php');
            $id = $this->idFromFilename($base);

            $migration = require $file;
            if (!$migration instanceof MigrationInterface) {
                throw new ConfigurationException(sprintf('Migration file "%s" must return MigrationInterface instance.', $file));
            }

            $out[] = ['id' => $id, 'migration' => $migration];
        }

        return $out;
    }

    private function idFromFilename(string $baseName): string
    {
        // YYYYMMDDHHMMSS_description
        if (!preg_match('/^(\d{14})_[a-z0-9][a-z0-9_]*$/', $baseName)) {
            throw new ConfigurationException(sprintf(
                'Invalid migration filename "%s". Expected format: YYYYMMDDHHMMSS_description.php',
                $baseName . '.php'
            ));
        }

        return $baseName;
    }
}

