<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

use PhpSoftBox\Database\Migrations\MigrationInterface;

/**
 * Миграция без операций: для проверки учёта применённых миграций.
 */
final class NoopMigration implements MigrationInterface
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
