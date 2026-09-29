<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Warmup;

use PDO;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Contracts\WarmupAwareConnectionInterface;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function assert;
use function bin2hex;
use function is_file;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(DatabaseFactory::class)]
#[CoversClass(ConnectionManager::class)]
#[CoversClass(Database::class)]
#[CoversClass(Connection::class)]
#[CoversMethod(DatabaseFactory::class, 'clearWarmup')]
#[CoversMethod(ConnectionManager::class, 'clearWarmup')]
#[CoversMethod(Database::class, 'clearWarmup')]
final class WarmupInvalidationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/psb_warmup_' . bin2hex(random_bytes(6)) . '.sqlite';

        $pdo = new PDO('sqlite:' . $this->file);

        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec('INSERT INTO products (id, name) VALUES (1, \'A\')');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * Проверим, что запись через main.write очищает warmup store подключений main.read и default той же группы.
     *
     * @see DatabaseFactory::create()
     */
    #[Test]
    public function writeConnectionInvalidatesReadConnectionsOfSameGroup(): void
    {
        $manager = new ConnectionManager(new DatabaseFactory($this->config()));

        $read    = $this->warmupConnection($manager, 'main.read');
        $default = $this->warmupConnection($manager, 'default');

        self::assertSame('A', $read->warmup()->one($this->lookup())['name'] ?? null);
        self::assertSame('A', $default->warmup()->one($this->lookup())['name'] ?? null);

        $manager->write('main')->execute('UPDATE products SET name = \'B\' WHERE id = 1');

        self::assertSame('B', $read->warmup()->one($this->lookup())['name'] ?? null);
        self::assertSame('B', $default->warmup()->one($this->lookup())['name'] ?? null);
    }

    /**
     * Проверим, что ConnectionManager::clearWarmup() сбрасывает прогретые строки (изменение сделано в обход подключения).
     *
     * @see ConnectionManager::clearWarmup()
     */
    #[Test]
    public function connectionManagerClearWarmupResetsStores(): void
    {
        $manager = new ConnectionManager(new DatabaseFactory($this->config()));

        $read = $this->warmupConnection($manager, 'main.read');

        self::assertSame('A', $read->warmup()->one($this->lookup())['name'] ?? null);

        $this->updateOutside('C');
        $manager->clearWarmup();

        self::assertSame('C', $read->warmup()->one($this->lookup())['name'] ?? null);
    }

    /**
     * Проверим, что Database::clearWarmup() сбрасывает прогретые строки всех подключений.
     *
     * @see Database::clearWarmup()
     */
    #[Test]
    public function databaseClearWarmupResetsStores(): void
    {
        $db   = Database::fromConfig($this->config());
        $read = $db->read('main');
        assert($read instanceof WarmupAwareConnectionInterface);

        self::assertSame('A', $read->warmup()->one($this->lookup())['name'] ?? null);

        $this->updateOutside('D');
        $db->clearWarmup();

        self::assertSame('D', $read->warmup()->one($this->lookup())['name'] ?? null);
    }

    /**
     * Проверим, что откат транзакции сбрасывает строки, прогретые внутри неё.
     *
     * @see Connection::transaction()
     */
    #[Test]
    public function rollbackClearsRowsWarmedInsideTransaction(): void
    {
        $manager = new ConnectionManager(new DatabaseFactory($this->config()));

        $write = $this->warmupConnection($manager, 'main.write');

        try {
            $write->transaction(function (WarmupAwareConnectionInterface $tx): void {
                $tx->execute('UPDATE products SET name = \'E\' WHERE id = 1');
                self::assertSame('E', $tx->warmup()->one($this->lookup())['name'] ?? null);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // Ожидаемый откат.
        }

        self::assertSame('A', $write->warmup()->one($this->lookup())['name'] ?? null);
    }

    /**
     * Проверим, что некорректный warmup.max_entries в конфиге отклоняется.
     *
     * @see DatabaseFactory::create()
     */
    #[Test]
    public function rejectsInvalidMaxEntriesConfig(): void
    {
        $factory = new DatabaseFactory(['warmup' => ['max_entries' => 0]] + $this->config());

        $this->expectException(ConfigurationException::class);

        $factory->create('main.read');
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => 'sqlite:///' . $this->file, 'readonly' => true],
                    'write' => ['dsn' => 'sqlite:///' . $this->file],
                ],
            ],
        ];
    }

    private function warmupConnection(ConnectionManager $manager, string $name): WarmupAwareConnectionInterface
    {
        $connection = $manager->connection($name);
        assert($connection instanceof WarmupAwareConnectionInterface);

        return $connection;
    }

    private function lookup(): LookupSpec
    {
        return LookupSpec::forTable('products')->lookupColumn('id')->value(1);
    }

    private function updateOutside(string $name): void
    {
        $pdo = new PDO('sqlite:' . $this->file);

        $pdo->exec('UPDATE products SET name = \'' . $name . '\' WHERE id = 1');
    }
}
