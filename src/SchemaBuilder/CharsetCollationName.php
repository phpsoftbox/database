<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\SchemaBuilder;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function preg_match;
use function sprintf;
use function trim;

/**
 * Проверяет имена charset/collation, но не их наличие и совместимость на сервере.
 *
 * @internal
 */
final class CharsetCollationName
{
    public static function normalize(string $name, string $option): string
    {
        $name = trim($name, ' ');
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $name) !== 1) {
            throw new ConfigurationException(sprintf(
                '%s must be a non-empty name containing only ASCII letters, digits and underscores.',
                $option,
            ));
        }

        return $name;
    }
}
