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

use function is_string;
use function sprintf;

abstract class AbstractMySqlDriver implements DriverInterface
{
    final public function validate(Dsn $dsn): void
    {
        if ($dsn->driver !== $this->name()) {
            throw new ConfigurationException(sprintf(
                '%s driver can handle only "%s" DSN.',
                $this->displayName(),
                $this->name(),
            ));
        }
        if ($dsn->host === null || $dsn->host === '') {
            throw new ConfigurationException($this->displayName() . ' DSN must contain host.');
        }
        if ($dsn->database === null || $dsn->database === '') {
            throw new ConfigurationException($this->displayName() . ' DSN must contain database name.');
        }
    }

    final public function pdoDsn(Dsn $dsn): string
    {
        $this->validate($dsn);

        $host   = $dsn->host;
        $port   = $dsn->port ?? 3306;
        $dbname = $dsn->database;

        $charset = $dsn->params['charset'] ?? null;

        $base = sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);
        if (is_string($charset) && $charset !== '') {
            $base .= ';charset=' . $charset;
        }

        return $base;
    }

    final public function defaultPdoOptions(): array
    {
        return [
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    final public function createQuoter(): QuoterInterface
    {
        return new MySqlQuoter();
    }

    final public function createQueryCompiler(): QueryCompilerInterface
    {
        return new StandardQueryCompiler($this->createQuoter());
    }

    abstract protected function displayName(): string;
}
