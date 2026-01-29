<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

/**
 * Формат колонки, на который распространяются useCurrent/useCurrentOnUpdate.
 */
enum UseCurrentFormatsEnum: string
{
    case DATETIME  = 'datetime';
    case TIMESTAMP = 'timestamp';
}
