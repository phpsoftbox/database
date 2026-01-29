<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Postgres\FullText;

enum PgFullTextRankFunction: string
{
    case Rank   = 'ts_rank';
    case RankCd = 'ts_rank_cd';
}
