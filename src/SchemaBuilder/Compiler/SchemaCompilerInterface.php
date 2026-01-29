<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder\Compiler;

use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

interface SchemaCompilerInterface
{
    public function compileCreateTable(TableBlueprint $table): string;

    public function compileCreateTableIfNotExists(TableBlueprint $table): string;

    public function compileDropIfExists(string $table): string;

    public function compileDropTable(string $table): string;

    /**
     * Компилирует ALTER TABLE ... ADD COLUMN для переданных колонок blueprint'а.
     *
     * @return list<string>
     */
    public function compileAlterTableAddColumns(TableBlueprint $table): array;

    /**
     * Переименование таблицы.
     */
    public function compileRenameTable(string $from, string $to): string;

    /**
     * Компилирует CREATE INDEX/CREATE UNIQUE INDEX для таблицы.
     *
     * @return list<string>
     */
    public function compileCreateIndexes(TableBlueprint $table): array;
}
