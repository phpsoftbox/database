<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Dsn\Dsn;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\QueryBuilder\Compiler\QueryCompilerInterface;
use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\QueryBuilder\Quoting\AnsiQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function str_starts_with;

final class SqliteDriver implements DriverInterface
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function validate(Dsn $dsn): void
    {
        if ($dsn->driver !== 'sqlite') {
            throw new ConfigurationException('SqliteDriver can handle only sqlite DSN.');
        }
        if ($dsn->path === null || $dsn->path === '') {
            throw new ConfigurationException('SQLite DSN must contain a path.');
        }
    }

    public function pdoDsn(Dsn $dsn): string
    {
        $this->validate($dsn);

        // Для PDO: sqlite::memory: или sqlite:/abs/path или sqlite:relative/path
        if ($dsn->path === ':memory:') {
            return 'sqlite::memory:';
        }

        // Если путь абсолютный, PDO ожидает sqlite:/abs/path (один слеш)
        if (str_starts_with($dsn->path, '/')) {
            return 'sqlite:' . $dsn->path;
        }

        return 'sqlite:' . $dsn->path;
    }

    public function defaultPdoOptions(): array
    {
        return [];
    }

    public function createQuoter(): QuoterInterface
    {
        return new AnsiQuoter();
    }

    public function createQueryCompiler(): QueryCompilerInterface
    {
        return new StandardQueryCompiler($this->createQuoter());
    }
}
