<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PhpSoftBox\Database\Migrations\FileMigrationLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function bin2hex;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(FileMigrationLoader::class)]
#[CoversMethod(FileMigrationLoader::class, 'load')]
final class FileMigrationLoaderOrderTest extends TestCase
{
    /**
     * Проверим, что рекурсивная загрузка упорядочивает миграции по имени (времени), а не по пути подкаталога.
     *
     * @see FileMigrationLoader::load()
     */
    #[Test]
    public function recursiveLoadSortsByMigrationName(): void
    {
        $dir   = sys_get_temp_dir() . '/psb_migrations_order_' . bin2hex(random_bytes(6));
        $code  = "<?php\n\nreturn new \\PhpSoftBox\\Database\\Tests\\Utils\\NoopMigration();\n";
        $files = [
            $dir . '/a_module/20260102000000_second.php',
            $dir . '/b_module/20260101000000_first.php',
        ];

        mkdir($dir . '/a_module', 0777, true);
        mkdir($dir . '/b_module', 0777, true);
        foreach ($files as $file) {
            file_put_contents($file, $code);
        }

        try {
            $loaded = new FileMigrationLoader()->load($dir, recursive: true);

            self::assertSame(['20260101000000_first', '20260102000000_second'], array_column($loaded, 'id'));
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($dir . '/a_module');
            rmdir($dir . '/b_module');
            rmdir($dir);
        }
    }
}
