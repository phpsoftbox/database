<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Exception;

/**
 * Исключение, выбрасываемое при попытке выполнить запись в read-only подключении.
 */
final class ReadOnlyException extends DatabaseException
{
}
