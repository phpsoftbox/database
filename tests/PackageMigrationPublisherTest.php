<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Migrations\PackageMigrationPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

#[CoversClass(PackageMigrationPublisher::class)]
final class PackageMigrationPublisherTest extends TestCase
{
    /**
     * Проверяет публикацию миграций из vendor package в директорию приложения.
     */
    #[Test]
    public function publishesPackageMigrations(): void
    {
        $root   = sys_get_temp_dir() . '/psb_migrations_publish_' . bin2hex(random_bytes(6));
        $source = $root . '/vendor/phpsoftbox/auth/database/migrations';
        $target = $root . '/database/migrations/default';
        $this->mkdir($source);
        $this->mkdir($target);

        file_put_contents($source . '/20260618000000_create_users.php', '<?php return null;');

        $published = new PackageMigrationPublisher()->publish(
            vendorPath: $root . '/vendor',
            targetPath: $target,
            package: 'phpsoftbox/auth',
        );

        self::assertCount(1, $published);
        self::assertSame('published', $published[0]['status']);
        self::assertFileExists($target . '/20260618000000_create_users.php');
    }

    /**
     * Проверяет, что publisher умеет находить миграции всех пакетов в vendor.
     */
    #[Test]
    public function publishesAllPackageMigrationsWhenPackageIsNotSpecified(): void
    {
        $root        = sys_get_temp_dir() . '/psb_migrations_publish_' . bin2hex(random_bytes(6));
        $authSource  = $root . '/vendor/phpsoftbox/auth/database/migrations';
        $queueSource = $root . '/vendor/phpsoftbox/queue/database/migrations';
        $target      = $root . '/database/migrations/default';
        $this->mkdir($authSource);
        $this->mkdir($queueSource);
        $this->mkdir($target);

        file_put_contents($authSource . '/20260618000000_create_users.php', '<?php return null;');
        file_put_contents($queueSource . '/20260618000100_create_jobs.php', '<?php return null;');

        $published = new PackageMigrationPublisher()->publish(
            vendorPath: $root . '/vendor',
            targetPath: $target,
        );

        self::assertCount(2, $published);
        self::assertFileExists($target . '/20260618000000_create_users.php');
        self::assertFileExists($target . '/20260618000100_create_jobs.php');
    }

    /**
     * Проверяет, что существующие файлы не перезаписываются без force.
     */
    #[Test]
    public function skipsExistingMigrationsWithoutForce(): void
    {
        $root   = sys_get_temp_dir() . '/psb_migrations_publish_' . bin2hex(random_bytes(6));
        $source = $root . '/vendor/phpsoftbox/auth/database/migrations';
        $target = $root . '/database/migrations/default';
        $this->mkdir($source);
        $this->mkdir($target);

        file_put_contents($source . '/20260618000000_create_users.php', 'source');
        file_put_contents($target . '/20260618000000_create_users.php', 'target');

        $published = new PackageMigrationPublisher()->publish($root . '/vendor', $target, 'phpsoftbox/auth');

        self::assertSame('skipped', $published[0]['status']);
        self::assertSame('target', file_get_contents($target . '/20260618000000_create_users.php'));
    }

    private function mkdir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}
