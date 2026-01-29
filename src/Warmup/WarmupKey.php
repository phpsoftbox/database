<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

use JsonException;
use Stringable;

use function hash;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function json_encode;
use function ksort;
use function method_exists;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class WarmupKey
{
    /**
     * @param array<string, mixed> $key
     */
    public function __construct(
        public string $connection,
        public string $table,
        public array $key,
    ) {
    }

    public static function single(
        string $connection,
        string $table,
        string $column,
        mixed $value,
    ): self {
        return new self($connection, $table, [$column => $value]);
    }

    /**
     * @param array<string, mixed> $key
     */
    public static function composite(
        string $connection,
        string $table,
        array $key,
    ): self {
        return new self($connection, $table, $key);
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode([
            'connection' => $this->connection,
            'table'      => $this->table,
            'key'        => self::normalizeArray($this->key),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    private static function normalizeArray(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            $values[$key] = self::normalizeValue($value);
        }

        return $values;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        if ($value instanceof Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        if (is_array($value)) {
            return self::normalizeArray($value);
        }

        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
