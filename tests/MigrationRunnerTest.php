<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\Migrations\FileMigrationLoader;
use PhpSoftBox\Database\Migrations\MigrationPlan;
use PhpSoftBox\Database\Migrations\MigrationRunner;
use PhpSoftBox\Database\Migrations\SqlMigrationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(SqlMigrationRepository::class)]
#[CoversClass(FileMigrationLoader::class)]
#[CoversClass(MigrationPlan::class)]
#[CoversClass(AbstractMigration::class)]
final class MigrationRunnerTest extends TestCase
{
    /**
     * Проверяет, что MigrationRunner применяет миграции по порядку, записывает их в migrations-таблицу
     * и при повторном запуске не применяет уже применённые.
     */
    #[Test]
    public function appliesMigrationsOnce(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'write' => [
                        'dsn'      => 'sqlite:///:memory:',
                        'readonly' => false,
                    ],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        $runner = new MigrationRunner(
            connections: $manager,
            repository: new SqlMigrationRepository('migrations'),
            connectionName: 'main',
        );

        $dir = sys_get_temp_dir() . '/mg_db_migrations_' . bin2hex(random_bytes(6));
        self::assertTrue(@mkdir($dir, 0777, true) || is_dir($dir));

        $file1 = $dir . '/20251226090000_create_users.php';
        file_put_contents($file1, <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void
    {
        $this->schema()->create('users', function (\PhpSoftBox\Database\SchemaBuilder\TableBlueprint $table): void {
            $table->id();
            $table->text('name');
            $table->boolean('is_active')->default(true);
            $table->json('meta');
        });
    }
};
PHP);

        $file2 = $dir . '/20251226090100_add_email.php';
        file_put_contents($file2, <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void
    {
        $this->schema()->addColumn('users', function (\PhpSoftBox\Database\SchemaBuilder\TableBlueprint $table): void {
            $table->string('email', 255)->nullable();
        });
    }
};
PHP);

        $loader = new FileMigrationLoader();
        $plan   = new MigrationPlan();

        foreach ($loader->load($dir) as $item) {
            $plan->add($item['id'], $item['migration']);
        }

        $applied = $runner->migrate($plan);
        self::assertSame(['20251226090000_create_users', '20251226090100_add_email'], $applied);

        $appliedAgain = $runner->migrate($plan);
        self::assertSame([], $appliedAgain);

        $conn = $manager->write('main');
        $rows = $conn->fetchAll(
            "SELECT name FROM migrations WHERE connection_name = 'main' ORDER BY id",
        );
        self::assertCount(2, $rows);
        self::assertSame('20251226090000_create_users', $rows[0]['name']);
        self::assertSame('20251226090100_add_email', $rows[1]['name']);

        // cleanup
        @unlink($file1);
        @unlink($file2);
        @rmdir($dir);
    }

    /**
     * Проверяет, что rollback откатывает последние миграции и удаляет запись из репозитория.
     */
    #[Test]
    public function rollbacksLastMigration(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'write' => [
                        'dsn'      => 'sqlite:///:memory:',
                        'readonly' => false,
                    ],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        $runner = new MigrationRunner(
            connections: $manager,
            repository: new SqlMigrationRepository('migrations'),
            connectionName: 'main',
        );

        $dir = sys_get_temp_dir() . '/mg_db_migrations_' . bin2hex(random_bytes(6));
        self::assertTrue(@mkdir($dir, 0777, true) || is_dir($dir));

        $file1 = $dir . '/20251226090000_first.php';
        file_put_contents($file1, <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
PHP);

        $file2 = $dir . '/20251226090100_second.php';
        file_put_contents($file2, <<<'PHP'
<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;

return new class extends AbstractMigration {
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
PHP);

        $loader = new FileMigrationLoader();
        $plan   = new MigrationPlan();

        foreach ($loader->load($dir) as $item) {
            $plan->add($item['id'], $item['migration']);
        }

        $runner->migrate($plan);

        $rolledBack = $runner->rollback($plan, 1);
        self::assertSame(['20251226090100_second'], $rolledBack);

        $rows = $manager->write('main')->fetchAll(
            "SELECT name FROM migrations WHERE connection_name = 'main' ORDER BY id",
        );
        self::assertCount(1, $rows);
        self::assertSame('20251226090000_first', $rows[0]['name']);

        @unlink($file1);
        @unlink($file2);
        @rmdir($dir);
    }
}
