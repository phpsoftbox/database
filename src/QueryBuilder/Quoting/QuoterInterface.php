<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder\Quoting;

interface QuoterInterface
{
    /**
     * Экранирует одиночный идентификатор (без точки).
     */
    public function ident(string $ident): string;

    /**
     * Экранирует идентификатор с dot-нотацией (например: table.column).
     */
    public function dotted(string $ident): string;

    /**
     * Экранирует алиас (в SELECT/FROM/JOIN).
     */
    public function alias(string $alias): string;
}
