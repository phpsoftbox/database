<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

use PDO;
use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Dsn\Dsn;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\QueryBuilder\Compiler\QueryCompilerInterface;
use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\QueryBuilder\Quoting\MySqlQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function in_array;
use function is_string;
use function sprintf;

final class MariaDbDriver implements DriverInterface
{
    public function name(): string
    {
        return 'mariadb';
    }

    public function validate(Dsn $dsn): void
    {
        if (!in_array($dsn->driver, ['mariadb', 'mysql'], true)) {
            throw new ConfigurationException('MariaDbDriver can handle only mariadb/mysql DSN.');
        }
        if ($dsn->host === null || $dsn->host === '') {
            throw new ConfigurationException('MariaDB DSN must contain host.');
        }
        if ($dsn->database === null || $dsn->database === '') {
            throw new ConfigurationException('MariaDB DSN must contain database name.');
        }
    }

    public function pdoDsn(Dsn $dsn): string
    {
        $this->validate($dsn);

        $host   = $dsn->host;
        $port   = $dsn->port ?? 3306;
        $dbname = $dsn->database;

        // charset можно пробросить из query params, если нужно
        $charset = $dsn->params['charset'] ?? null;

        $base = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
        if (is_string($charset) && $charset !== '') {
            $base .= ';charset=' . $charset;
        }

        return $base;
    }

    public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    public function createQuoter(): QuoterInterface
    {
        return new MySqlQuoter();
    }

    public function createQueryCompiler(): QueryCompilerInterface
    {
        return new StandardQueryCompiler($this->createQuoter());
    }
}
