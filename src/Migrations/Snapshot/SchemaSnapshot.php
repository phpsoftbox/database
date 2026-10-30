<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

/**
 * Снимок схемы базы после применения набора миграций.
 *
 * Пустая база разворачивается из снимка, а покрытые им миграции отмечаются применёнными;
 * более новые миграции затем применяются обычным путём.
 */
final readonly class SchemaSnapshot
{
    /**
     * @param list<string> $migrations Миграции, результат которых содержит снимок.
     * @param list<string> $dataTables Таблицы без префикса, строки которых сохранены в снимке.
     * @param list<string> $statements SQL-инструкции без завершающей точки с запятой.
     */
    public function __construct(
        public string $driver,
        public string $prefix,
        public array $migrations,
        public array $dataTables,
        public array $statements,
    ) {
    }
}
