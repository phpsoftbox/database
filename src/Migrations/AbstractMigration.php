<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use LogicException;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Schema\SchemaManagerInterface;
use PhpSoftBox\Database\SchemaBuilder\SchemaBuilderInterface;

/**
 * Базовый класс миграции.
 *
 * Идентификатор миграции (id) берётся из имени файла (без расширения).
 * Формат имени файла: YYYYMMDDHHMMSS_description.php
 */
abstract class AbstractMigration implements MigrationInterface
{
    private ?MigrationContext $context = null;

    /**
     * Внутренний метод: Runner устанавливает контекст перед выполнением.
     */
    final public function setContext(MigrationContext $context): void
    {
        $this->context = $context;
    }

    /**
     * Возвращает read-only подключение, с которым выполняется миграция.
     */
    final protected function connection(): ConnectionInterface
    {
        if ($this->context === null) {
            throw new LogicException('Migration context is not set. Run migrations via MigrationRunner.');
        }

        return $this->context->connection();
    }

    /**
     * Возвращает schema builder (DDL/изменение схемы).
     */
    final protected function schema(): SchemaBuilderInterface
    {
        if ($this->context === null) {
            throw new LogicException('Migration context is not set. Run migrations via MigrationRunner.');
        }

        return $this->context->schema();
    }

    /**
     * Возвращает schema manager (introspection).
     */
    final protected function introspect(): SchemaManagerInterface
    {
        if ($this->context === null) {
            throw new LogicException('Migration context is not set. Run migrations via MigrationRunner.');
        }

        return $this->context->schemaManager();
    }

    /**
     * Применяет миграцию.
     */
    abstract public function up(): void;

    /**
     * Откат миграции.
     *
     * В MVP можно оставлять пустым.
     */
    public function down(): void
    {
        // no-op
    }
}
