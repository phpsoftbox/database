<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

use InvalidArgumentException;

use function array_key_first;
use function count;

/**
 * Хранилище прогретых строк в памяти процесса.
 *
 * Размер ограничен $maxEntries: при переполнении вытесняются самые старые записи (FIFO), чтобы в долгоживущих
 * воркерах store не рос без ограничений. Между запросами/задачами воркера store нужно очищать через clear()
 * (или DatabaseFactory::clearWarmup()/ConnectionManager::clearWarmup()/Database::clearWarmup()).
 */
final class WarmupStore
{
    public const int DEFAULT_MAX_ENTRIES = 10_000;

    /**
     * @var array<string, WarmupEntry>
     */
    private array $entries = [];

    public function __construct(
        private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ) {
        if ($maxEntries < 1) {
            throw new InvalidArgumentException('Warmup store max entries must be greater than 0.');
        }
    }

    public function get(WarmupKey $key): ?WarmupEntry
    {
        return $this->entries[$key->hash()] ?? null;
    }

    public function set(WarmupKey $key, WarmupEntry $entry): void
    {
        $hash = $key->hash();

        // Перезапись переносит запись в конец очереди вытеснения.
        unset($this->entries[$hash]);

        while (count($this->entries) >= $this->maxEntries) {
            unset($this->entries[array_key_first($this->entries)]);
        }

        $this->entries[$hash] = $entry;
    }

    public function delete(WarmupKey $key): void
    {
        unset($this->entries[$key->hash()]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Количество записей в store.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    public function maxEntries(): int
    {
        return $this->maxEntries;
    }
}
