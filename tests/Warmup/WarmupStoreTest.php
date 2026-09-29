<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Warmup;

use InvalidArgumentException;
use PhpSoftBox\Database\Warmup\WarmupEntry;
use PhpSoftBox\Database\Warmup\WarmupKey;
use PhpSoftBox\Database\Warmup\WarmupStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WarmupStore::class)]
#[CoversMethod(WarmupStore::class, 'set')]
final class WarmupStoreTest extends TestCase
{
    /**
     * Проверим, что при достижении лимита вытесняется самая старая запись.
     *
     * @see WarmupStore::set()
     */
    #[Test]
    public function evictsOldestEntryWhenLimitReached(): void
    {
        $store = new WarmupStore(maxEntries: 2);

        $store->set($this->key(1), WarmupEntry::row(['id' => 1]));
        $store->set($this->key(2), WarmupEntry::row(['id' => 2]));
        $store->set($this->key(3), WarmupEntry::row(['id' => 3]));

        self::assertSame(2, $store->count());
        self::assertNull($store->get($this->key(1)));
        self::assertNotNull($store->get($this->key(3)));
    }

    /**
     * Проверим, что перезапись существующего ключа не вытесняет другие записи и переносит ключ в конец очереди.
     *
     * @see WarmupStore::set()
     */
    #[Test]
    public function overwriteMovesEntryToQueueEnd(): void
    {
        $store = new WarmupStore(maxEntries: 2);

        $store->set($this->key(1), WarmupEntry::row(['id' => 1]));
        $store->set($this->key(2), WarmupEntry::row(['id' => 2]));
        $store->set($this->key(1), WarmupEntry::row(['id' => 1]));
        $store->set($this->key(3), WarmupEntry::row(['id' => 3]));

        self::assertNotNull($store->get($this->key(1)));
        self::assertNull($store->get($this->key(2)));
    }

    /**
     * Проверим, что лимит меньше 1 отклоняется.
     *
     * @see WarmupStore::__construct()
     */
    #[Test]
    public function rejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarmupStore(maxEntries: 0);
    }

    private function key(int $id): WarmupKey
    {
        return WarmupKey::single('main', 'products', 'id', $id);
    }
}
