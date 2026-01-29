<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Postgres\FullText;

enum PgFullTextQueryMode: string
{
    case Plain     = 'plain';
    case Phrase    = 'phrase';
    case Websearch = 'websearch';

    public function functionName(): string
    {
        return match ($this) {
            self::Plain     => 'plainto_tsquery',
            self::Phrase    => 'phraseto_tsquery',
            self::Websearch => 'websearch_to_tsquery',
        };
    }
}
