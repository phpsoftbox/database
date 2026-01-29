<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use DateTimeImmutable;
use PhpSoftBox\Database\Exception\ConfigurationException;

use function file_exists;
use function file_put_contents;
use function is_dir;
use function preg_replace;
use function rtrim;
use function strtolower;
use function trim;

final class MigrationCreator
{
    public function create(string $directory, string $name): string
    {
        $slug      = $this->slug($name);
        $timestamp = new DateTimeImmutable('now')->format('YmdHis');
        $filename  = $timestamp . '_' . $slug . '.php';

        $dir = rtrim($directory, '/');
        if (!is_dir($dir)) {
            throw new ConfigurationException('Migrations directory does not exist: ' . $dir);
        }

        $path = $dir . '/' . $filename;
        if (file_exists($path)) {
            throw new ConfigurationException('Migration already exists: ' . $path);
        }

        $stub = $this->stub();
        if (file_put_contents($path, $stub) === false) {
            throw new ConfigurationException('Failed to write migration file: ' . $path);
        }

        return $path;
    }

    private function slug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        if ($slug === '') {
            throw new ConfigurationException('Migration name must contain letters or digits.');
        }

        return $slug;
    }

    private function stub(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;

return new class () extends AbstractMigration {
    public function up(): void
    {
        // TODO: описать изменения схемы
    }

    public function down(): void
    {
        // TODO: описать откат изменений
    }
};
PHP;
    }
}
