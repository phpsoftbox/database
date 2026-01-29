<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

final class WarmupStore
{
    /**
     * @var array<string, WarmupEntry>
     */
    private array $entries = [];

    public function get(WarmupKey $key): ?WarmupEntry
    {
        return $this->entries[$key->hash()] ?? null;
    }

    public function set(WarmupKey $key, WarmupEntry $entry): void
    {
        $this->entries[$key->hash()] = $entry;
    }

    public function delete(WarmupKey $key): void
    {
        unset($this->entries[$key->hash()]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
