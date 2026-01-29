<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Contracts;

use PhpSoftBox\Database\Dsn\Dsn;
use PhpSoftBox\Database\QueryBuilder\Compiler\QueryCompilerInterface;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

interface DriverInterface
{
    /**
     * Возвращает имя драйвера (например: sqlite, postgres, mariadb).
     */
    public function name(): string;

    /**
     * Создаёт экземпляр PDO DSN, совместимый с конкретным драйвером.
     */
    public function pdoDsn(Dsn $dsn): string;

    /**
     * Валидирует DSN и бросает исключение конфигурации при ошибках.
     */
    public function validate(Dsn $dsn): void;

    /**
     * Возвращает PDO options по умолчанию для данного драйвера.
     *
     * Важно: options из конфигурации соединения должны иметь приоритет над этими значениями.
     *
     * @return array<int, mixed>
     */
    public function defaultPdoOptions(): array;

    /**
     * Создаёт Quoter для данного драйвера.
     */
    public function createQuoter(): QuoterInterface;

    /**
     * Создаёт компилятор SQL (QueryBuilder -> SQL) для данного драйвера.
     */
    public function createQueryCompiler(): QueryCompilerInterface;
}
