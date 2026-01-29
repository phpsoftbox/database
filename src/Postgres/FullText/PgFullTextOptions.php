<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Postgres\FullText;

use InvalidArgumentException;

use function preg_match;
use function str_replace;
use function trim;

final class PgFullTextOptions
{
    public readonly string $config;

    public function __construct(
        string $config = 'simple',
        public readonly PgFullTextQueryMode $queryMode = PgFullTextQueryMode::Websearch,
        public readonly PgFullTextRankFunction $rankFunction = PgFullTextRankFunction::RankCd,
        public readonly ?int $normalization = null,
        public readonly bool $skipEmptyQuery = true,
    ) {
        $config = trim($config);
        if ($config === '') {
            throw new InvalidArgumentException('PostgreSQL full-text config must be non-empty.');
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $config) !== 1) {
            throw new InvalidArgumentException('Invalid PostgreSQL full-text config: ' . $config);
        }

        if ($normalization !== null && $normalization < 0) {
            throw new InvalidArgumentException('PostgreSQL full-text rank normalization must be greater than or equal to zero.');
        }

        $this->config = $config;
    }

    public function configSql(): string
    {
        return "'" . str_replace("'", "''", $this->config) . "'";
    }

    public function queryFunctionSql(): string
    {
        return $this->queryMode->functionName();
    }

    public function rankFunctionSql(): string
    {
        return $this->rankFunction->value;
    }
}
