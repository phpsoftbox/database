<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

/**
 * Контракт для миграции.
 *
 * Важно:
 * - идентификатор миграции (id) берётся из имени файла
 * - подключение не передаётся в методы: миграция работает через API базового класса (AbstractMigration)
 */
interface MigrationInterface
{
    /**
     * Применяет миграцию.
     */
    public function up(): void;

    /**
     * Откат миграции.
     */
    public function down(): void;
}
