<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Dsn;

/**
 * Нормализованное представление универсального URL-style DSN.
 */
final readonly class Dsn
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public string $driver,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $path = null,
        public ?string $user = null,
        public ?string $password = null,
        public array $params = [],
    ) {
    }
}
