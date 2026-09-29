<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Driver;

use PDO;
use PhpSoftBox\Database\Contracts\DriverInterface;
use PhpSoftBox\Database\Dsn\Dsn;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\QueryBuilder\Compiler\QueryCompilerInterface;
use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\QueryBuilder\Quoting\AnsiQuoter;
use PhpSoftBox\Database\QueryBuilder\Quoting\QuoterInterface;

use function in_array;
use function preg_match;
use function sprintf;

final class PostgresDriver implements DriverInterface
{
    public function name(): string
    {
        return 'postgres';
    }

    public function validate(Dsn $dsn): void
    {
        if (!in_array($dsn->driver, ['postgres', 'pgsql'], true)) {
            throw new ConfigurationException('PostgresDriver can handle only postgres/pgsql DSN.');
        }
        if ($dsn->host === null || $dsn->host === '') {
            throw new ConfigurationException('Postgres DSN must contain host.');
        }
        if ($dsn->database === null || $dsn->database === '') {
            throw new ConfigurationException('Postgres DSN must contain database name.');
        }
    }

    public function pdoDsn(Dsn $dsn): string
    {
        $this->validate($dsn);

        $host   = $dsn->host;
        $port   = $dsn->port ?? 5432;
        $dbname = $dsn->database;

        // PDO pgsql:host=...;port=...;dbname=...
        $pdoDsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);

        // Query-параметры DSN (sslmode, sslrootcert, application_name, connect_timeout, ...) передаются в libpq.
        foreach ($dsn->params as $name => $value) {
            $name = (string) $name;
            if (preg_match('/^[a-z_]+$/', $name) !== 1 || preg_match('/^[^\s;\'\\\\]*$/', $value) !== 1) {
                throw new ConfigurationException(sprintf('Invalid Postgres DSN parameter "%s".', $name));
            }

            if (in_array($name, ['host', 'port', 'dbname', 'user', 'password'], true)) {
                throw new ConfigurationException(sprintf('Postgres DSN parameter "%s" must be set in the URL part, not in the query string.', $name));
            }

            $pdoDsn .= ';' . $name . '=' . $value;
        }

        return $pdoDsn;
    }

    public function defaultPdoOptions(): array
    {
        // Базовые дефолты; конкретные проекты могут переопределять через config['connections'][..]['options'].
        return [
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
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
